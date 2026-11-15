<?php
/**
 * Demo task: grayscale copies of images, uploaded as artifacts.
 *
 * Shows payloads (a URL instead of stored data), per-unit authorization, file uploads, output checks and an
 * idempotent finalization which publishes the files.
 *
 * @package WwDemo
 */

namespace WwDemo;

defined( 'ABSPATH' ) || exit;

/**
 * One unit per image.
 */
final class Grayscale_Task extends Worker\Task {
	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'grayscale';
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan( $input, ?string $cursor, Worker\Outputs $outputs ): array {
		$units = array();

		foreach ( (array) ( $input['ids'] ?? array() ) as $id ) {
			$units[] = array(
				'key'   => 'image-' . (int) $id,
				'input' => array( 'id' => (int) $id ),
			);
		}

		return array(
			'units' => $units,
			'next'  => null,
		);
	}

	/**
	 * Only users who can edit the image work on it.
	 *
	 * @param \WP_User $user       User.
	 * @param mixed    $unit_input Unit input.
	 * @param mixed    $job_input  Job input.
	 * @return bool
	 */
	public function authorize( \WP_User $user, $unit_input, $job_input ): bool {
		return user_can( $user, 'edit_post', (int) $unit_input['id'] );
	}

	/**
	 * The browser gets the image URL, computed when the unit is claimed.
	 *
	 * @param mixed $unit_input Unit input.
	 * @param mixed $job_input  Job input.
	 * @return array|\WP_Error
	 */
	public function payload( $unit_input, $job_input ) {
		$url = wp_get_attachment_url( (int) $unit_input['id'] );

		return $url ? array( 'url' => $url ) : new \WP_Error( 'demo_missing', 'The image no longer exists.' );
	}

	/**
	 * The upload must be a PNG with the size the browser reports.
	 *
	 * @param mixed                    $unit_input Unit input.
	 * @param mixed                    $result     `width`, `height`.
	 * @param Worker\Artifact[]  $artifacts  Uploaded files.
	 * @param mixed                    $job_input  Job input.
	 * @return true|\WP_Error
	 */
	public function validate( $unit_input, $result, array $artifacts, $job_input ) {
		$file = $artifacts['gray.png'] ?? null;

		if ( ! $file ) {
			return new \WP_Error( 'demo_result', 'The image was not uploaded.' );
		}

		$size = @getimagesize( $file->path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $size || IMAGETYPE_PNG !== $size[2] || (int) $size[0] !== (int) ( $result['width'] ?? -1 ) || (int) $size[1] !== (int) ( $result['height'] ?? -1 ) ) {
			return new \WP_Error( 'demo_result', 'The uploaded file is not the expected PNG.' );
		}

		return true;
	}

	/**
	 * Publishes the copies next to the uploads. Idempotent: a retry copies the same files to the same place.
	 *
	 * @param mixed          $job_input Job input.
	 * @param Worker\Outputs $outputs   Results.
	 * @param int            $job_id    Job ID.
	 * @return array URLs of the copies.
	 */
	public function finalize( $job_input, Worker\Outputs $outputs, int $job_id ) {
		$uploads = wp_get_upload_dir();
		$dir     = $uploads['basedir'] . '/ww-demo';
		$urls    = array();

		wp_mkdir_p( $dir );

		foreach ( $outputs->results() as $key => $result ) {
			$artifacts = $outputs->artifacts( $key );
			if ( empty( $artifacts['gray.png'] ) ) {
				continue;
			}
			$name = sanitize_file_name( $key . '-gray.png' );
			if ( ! copy( $artifacts['gray.png']->path, "$dir/$name" ) ) {
				throw new \RuntimeException( 'Could not publish the copy.' ); // Temporary: retried.
			}
			$urls[ $key ] = $uploads['baseurl'] . '/ww-demo/' . $name;
		}

		return array(
			'images' => $urls,
			'failed' => $outputs->failures(),
		);
	}
}
