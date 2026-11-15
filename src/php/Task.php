<?php
/**
 * Base class of a task type: what the host plugin implements for each kind of browser work.
 *
 * @package WasmWorker
 */

namespace WasmWorker;

defined( 'ABSPATH' ) || exit;

/**
 * A task type splits a job into units, decides who may run them and what they receive, checks each result,
 * and applies the final outcome once. The browser side (the runner) is registered under the same type name.
 *
 * Only `type()`, `plan()`, `validate()` and `finalize()` are required; the other methods have safe defaults.
 */
abstract class Task {
	/**
	 * Unique name of the task type, also the name of its browser runner.
	 *
	 * @return string Lowercase letters, digits, `-` and `_`.
	 */
	abstract public function type(): string;

	/**
	 * Plans the units of a job, in steps for large jobs, and in stages for multi-pass work.
	 *
	 * Called once when the job is created, then again with the returned cursor (in later worker requests or
	 * cron) until `next` is null. Unit keys must be stable: planning the same step twice must give the same
	 * keys, the second insertion is ignored.
	 *
	 * Stages: return `wait => true` with a cursor to be called again only once every unit planned so far is
	 * done, with their results in `$outputs` (e.g. encode the segments, then one unit to join them).
	 *
	 * @param mixed       $input   Job input.
	 * @param string|null $cursor  Null for the first step, then the previous `next`.
	 * @param Outputs     $outputs Results of the units done so far (useful after a `wait`).
	 * @return array|\WP_Error {
	 *     @type array[]     $units List of units, each with `key` (string, unique in the job), `input` (JSON
	 *                              serializable) and optionally `cost` (int, used to fill batches, default 1).
	 *     @type string|null $next  Cursor of the next step, null when the plan is complete.
	 *     @type bool        $wait  With a cursor: plan the next step only when all the units so far are done.
	 *     @type mixed       $data  Optional: replaces the data of the job (JSON, e.g. what `finalize()` needs
	 *                              to put the results together), read with `$outputs->data()`.
	 * }
	 */
	abstract public function plan( $input, ?string $cursor, Outputs $outputs );

	/**
	 * Checks a unit result sent by a browser. Never trust it: the browser may be buggy or hostile.
	 *
	 * @param mixed      $unit_input Unit input.
	 * @param mixed      $result     Result sent by the browser.
	 * @param Artifact[] $artifacts  Files uploaded for this unit under the current lease, by key.
	 * @param mixed      $job_input  Job input.
	 * @return true|\WP_Error True to accept the result.
	 */
	abstract public function validate( $unit_input, $result, array $artifacts, $job_input );

	/**
	 * Applies the outcome of a job once all its units are done. Must be idempotent: when the PHP request dies
	 * halfway, it is called again later.
	 *
	 * Throw (or let an exception through) for a temporary problem: finalization is retried. Return a WP_Error
	 * to fail the job for good.
	 *
	 * @param mixed   $job_input Job input.
	 * @param Outputs $outputs   Unit results, failures and artifacts.
	 * @param int     $job_id    Job ID.
	 * @return mixed|\WP_Error JSON serializable job result.
	 */
	abstract public function finalize( $job_input, Outputs $outputs, int $job_id );

	/**
	 * Whether the current user may run a unit, on top of the kit's worker eligibility. Rechecked on every
	 * claim, renewal, checkpoint, upload and completion. Keep it cheap: it runs for every unit scanned.
	 *
	 * @param \WP_User $user       User.
	 * @param mixed    $unit_input Unit input.
	 * @param mixed    $job_input  Job input.
	 * @return bool
	 */
	public function authorize( \WP_User $user, $unit_input, $job_input ): bool {
		return true;
	}

	/**
	 * Affinity of a job: a batch only holds units of jobs with the same affinity, and a worker gets jobs with
	 * the affinity of its previous batch first (among the jobs of the same priority). Use it for whatever
	 * is expensive to switch in the worker, e.g. the language pair of a translation model.
	 *
	 * @param mixed $job_input Job input.
	 * @return string Up to 64 characters, empty for none.
	 */
	public function affinity( $job_input ): string {
		return '';
	}

	/**
	 * What the browser receives for a unit. Defaults to the unit input; override to add short-lived URLs or
	 * to leave out data the browser does not need.
	 *
	 * @param mixed $unit_input Unit input.
	 * @param mixed $job_input  Job input.
	 * @return mixed|\WP_Error JSON serializable payload. A WP_Error releases the unit for later.
	 */
	public function payload( $unit_input, $job_input ) {
		return $unit_input;
	}

	/**
	 * Settings of the task type.
	 *
	 * @return array {
	 *     @type int    $lease_seconds Lease duration, renewed by the browser while it works. Default 120.
	 *     @type int    $max_attempts  Attempts per unit before it fails. Default 3.
	 *     @type int    $backoff       Seconds before the first retry of a failed unit, doubled at each attempt,
	 *                                 at most 1 hour. Default 10. 0 to retry right away.
	 *     @type int    $max_runtime   Seconds an attempt may run, renewals included. Default 0 (no limit).
	 *     @type int    $batch_units   Maximum units per claim. Default 1 (raise it for small units).
	 *     @type int    $batch_cost    Maximum total `cost` per claim. Default 0 (no limit).
	 *     @type int    $max_leased    Units of this type running at once, all browsers together. Default 0 (no limit).
	 *     @type array  $rate_limit    `array( 'units' => 60, 'seconds' => 60 )`: units started per window, all
	 *                                 browsers together. Default none.
	 *     @type int    $priority      Default priority of the jobs of this type (higher first). Default 0.
	 *     @type string $on_failure    `continue` (default): a failed unit does not stop the job, `finalize()`
	 *                                 sees the failures. `fail_job`: the job fails at the first failed unit.
	 * }
	 */
	public function options(): array {
		return array();
	}
}
