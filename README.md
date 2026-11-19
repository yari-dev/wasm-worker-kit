# WASM worker kit for WordPress

A reusable base for WordPress plugins whose heavy work runs in WebAssembly in the browsers of the site's
editors (or in headless Node workers): image or video encoding, machine translation, transcription, any
per-item computation. The server keeps a durable job queue in MySQL; workers lease small units of work, run
them in a Web Worker, and send the results back. Jobs survive closed tabs, crashes, reloads and many workers
at once. No Redis, RabbitMQ, system cron or long-running PHP process is needed.

This is not a plugin: each host plugin bundles its own copy, under its own PHP namespace.

```
src/php/     Kit, Task, Queue, Rest, Cli, Monitor, Schema, Storage, Outputs, Artifact, autoload.php
src/js/      worker.js (inside the Web Worker), host.js (on the page), assets.js (engine files),
             dashboard.js (admin dashboard), node-worker.mjs (headless worker), index.d.ts (types)
tools/       vendor.mjs: copies the kit into a plugin under the plugin's namespace
examples/    demo-plugin: a complete host plugin (two task types, dashboard, background mode)
tests/       smoke.php + features.php (PHP), worker.test.mjs (browser runtime), race.php (concurrency)
```

## Features

| Area | What you get |
| --- | --- |
| Reliability | Database-authoritative queue, fenced leases (token + epoch + expiry checked in every write), at-least-once execution with exactly one accepted result per unit, idempotent completion IDs, crash and stall recovery without cron. |
| Retries | `max_attempts`, exponential backoff, permanent failures (`error.retry = false`), maximum runtime per attempt, failed units kept with their error (dead letters) and retried on demand. |
| Scheduling | Priorities, round-robin between jobs of the same priority, affinity (a batch holds one affinity, a worker gets the one it has loaded first), delayed jobs (`delay`, `run_at`), repeatable jobs on a WP-Cron schedule (idempotent per period). |
| Workflows | Job groups (one user request split into many jobs: summary, pause, cancel, retry), job dependencies (`after`), multi-stage jobs (plan the next stage from the results of the previous one), plans built in steps for very large jobs, data kept by the plan for `finalize()`, `finalize()` once per job and as the job's owner. |
| Flow control | Concurrency limit per task type across all workers (`max_leased`), rate limit (`rate_limit`), batches (`batch_units`, `batch_cost`), pause and resume per job or per task type. |
| Deduplication | Idempotency keys on jobs (`key`, `rerun`), stable unit keys. |
| Files | Lease-fenced uploads with SHA-256 checks, kept across attempts by checkpoints, private staging outside the web root. |
| Progress | Per-unit progress sent with the lease renewals, job progress including running units, checkpoints to resume long units. |
| Observability | Lifecycle action hooks, metrics per task type (throughput, failures, average and p95 duration), worker registry with capabilities, drop-in admin dashboard, WP-CLI commands. |
| Workers | Dedicated Web Workers (module or classic, one tab per browser, `concurrency` workers per tab), background mode on every admin page, batch runners (one engine call per batch, for ML inference), direct runs outside of the queue with the same engine (previews, interactive tools), headless Node worker with Application Passwords, capability detection (WebGPU, cores, memory). |
| Translations | The kit's messages use the host plugin's text domain (`vendor.mjs --text-domain`). |
| Isolation | Several plugins with different kit versions never share classes, tables, routes, options, hooks or locks. |

## Quick start

1. Copy the kit into your plugin, under your namespace and text domain (run it again to update):

   ```bash
   node tools/vendor.mjs --to ../my-plugin/lib/wasm-worker --namespace "MyPlugin\Worker" --text-domain my-plugin
   ```

2. Write a task type (PHP) and boot the kit:

   ```php
   require_once __DIR__ . '/lib/wasm-worker/php/autoload.php';

   final class Thumbnail_Task extends MyPlugin\Worker\Task {
       public function type(): string { return 'thumbnail'; }

       public function plan( $input, ?string $cursor, MyPlugin\Worker\Outputs $outputs ): array { // Split the job.
           return array( 'units' => array_map( fn( $id ) => array( 'key' => "img-$id", 'input' => array( 'id' => $id ) ), $input['ids'] ), 'next' => null );
       }
       public function payload( $unit, $job ) {                      // What the worker receives.
           return array( 'url' => wp_get_attachment_url( $unit['id'] ) );
       }
       public function validate( $unit, $result, array $artifacts, $job ) { // Never trust the worker.
           return isset( $artifacts['thumb.webp'] ) ? true : new WP_Error( 'missing', 'No file.' );
       }
       public function finalize( $job, MyPlugin\Worker\Outputs $outputs, int $job_id ) { // Once, idempotent.
           foreach ( $outputs->results() as $key => $result ) { /* move $outputs->artifacts( $key )['thumb.webp']->path */ }
           return array( 'done' => $outputs->counts()['completed'] );
       }
       public function options(): array {
           return array( 'max_leased' => 8, 'backoff' => 30 );
       }
   }

   $kit = new MyPlugin\Worker\Kit( array( 'id' => 'my-plugin', 'capability' => 'upload_files' ) );
   $kit->register( new Thumbnail_Task() );
   $kit->boot();

   register_activation_hook( __FILE__, array( $kit, 'install' ) );
   register_deactivation_hook( __FILE__, array( $kit, 'deactivate' ) );
   // uninstall.php: ( new MyPlugin\Worker\Kit( array( 'id' => 'my-plugin' ) ) )->uninstall();

   $job_id = $kit->enqueue( 'thumbnail', array( 'ids' => array( 12, 13 ) ), array( 'key' => 'thumbs-batch-7', 'priority' => 5 ) );
   ```

3. Write the runners (shared by the browser and the Node worker) and start them from your admin pages:

   ```js
   // assets/runners.js
   import { fetchAsset } from '../lib/wasm-worker/js/assets.js';

   export default { thumbnail: {
       async prepare() {                       // Once per worker, again after an engine crash.
           const wasm = await fetchAsset( ENCODER_URL, { sha256: ENCODER_SHA256 } );
           return ( await WebAssembly.instantiate( wasm, imports ) ).instance.exports;
       },
       async run( { input, engine, upload, checkpoint, resume, progress, signal, attempt } ) {
           const bytes = await ( await fetch( input.url, { signal } ) ).arrayBuffer();
           await upload( 'thumb.webp', encode( engine, bytes ), 'image/webp' );
           return { ok: true };
       },
   } };
   ```

   ```js
   // assets/worker.js
   import { serve } from '../lib/wasm-worker/js/worker.js';
   import runners from './runners.js';
   serve( { runners } );
   ```

   ```js
   // assets/admin.js (script module; data from Kit::dashboard_config() + workerUrl)
   import { watchQueue } from '../lib/wasm-worker/js/host.js';
   import { mountDashboard } from '../lib/wasm-worker/js/dashboard.js';
   watchQueue( config );                                                // Help in the background.
   mountDashboard( document.getElementById( 'my-dashboard' ), config ); // Optional admin dashboard.
   ```

   ```php
   wp_enqueue_script_module( 'my-plugin-admin', plugins_url( 'assets/admin.js', __FILE__ ) );
   add_filter( 'script_module_data_my-plugin-admin', fn() => $kit->dashboard_config() + array( 'workerUrl' => plugins_url( 'assets/worker.js', __FILE__ ) ) );
   ```

The [demo plugin](examples/demo-plugin) is the best starting point: copy it, rename it, run `vendor.mjs`.
To try it as it is, run `npm run demo` first (it copies the kit into `examples/demo-plugin/lib`, which is
generated and not committed), then install the `examples/demo-plugin` folder as a plugin.

## Task types (PHP)

| Method | Required | Purpose |
| --- | --- | --- |
| `type()` | yes | Name, also the runner's name. |
| `plan( $input, $cursor, $outputs )` | yes | Returns `units` (`key`, `input`, optional `cost`), `next` (cursor or null), and optionally `wait` and `data`. Large jobs plan in steps (the first step runs in `enqueue()`: keep it cheap, the queue continues the others in later requests); with `wait => true` the next step is planned only once all units so far are done, with their results in `$outputs` (stages: encode the segments, then join them). `data` replaces the job's data (`$outputs->data()`), e.g. what `finalize()` needs to put the results together. A `WP_Error` at the first step means the job is not created. Keys must be stable. |
| `validate( $unit, $result, $artifacts, $job )` | yes | `true` or `WP_Error`. A rejected result is a failed attempt. |
| `finalize( $job, $outputs, $job_id )` | yes | Applies the outcome, once. Must be idempotent. Throw for a temporary problem (retried after 5 min), return `WP_Error` to fail the job. |
| `authorize( $user, $unit, $job )` | no | Per-unit permission, rechecked on every renewal. |
| `affinity( $job )` | no | A string (up to 64 characters): a batch holds jobs of one affinity, and a worker gets the affinity of its previous batch first, among jobs of the same priority. For what is expensive to switch in a worker: the model of a language pair, a large dataset. |
| `payload( $unit, $job )` | no | What the worker gets (e.g. a URL computed at claim time instead of stored data). A `WP_Error` is a failed attempt. |
| `options()` | no | See below. |

| Option | Default | Meaning |
| --- | --- | --- |
| `lease_seconds` | 120 | Lease duration, renewed at a third of it while the unit runs. |
| `max_attempts` | 3 | Attempts per unit before it fails. |
| `backoff` | 10 | Seconds before the first retry, doubled at each attempt, at most 1 hour. 0 retries at once. |
| `max_runtime` | 0 | Seconds an attempt may run, renewals included (0: no limit). |
| `batch_units` / `batch_cost` | 1 / 0 | Units per claim, and their maximum total `cost` (0: no limit). |
| `max_leased` | 0 | Units of this type running at once, all workers together (0: no limit). |
| `rate_limit` | none | `array( 'units' => 60, 'seconds' => 60 )`: units started per window, all workers together. |
| `priority` | 0 | Default priority of the jobs of this type. |
| `on_failure` | `continue` | `continue`: `finalize()` sees the failures. `fail_job`: the job fails at the first failed unit. |
| `finalize_attempts` | 5 | A finalization that threw this many times fails the job. |
| `keep_artifacts` | `false` | Keeps a job's staged files after it completed (until it is pruned). |

`Outputs` gives `results()` and `completed()` (generators, memory-safe for large jobs; `completed()` gives each input with its result), `failures()`, `artifacts( $key )`, `data()` and `counts()`.

## The Kit (PHP)

```php
$kit->enqueue( $type, $input, array(
    'key'      => 'import-42',  // Idempotency: the existing job with this key is returned.
    'rerun'    => true,         // With a key: create a new job once the existing one is over.
    'priority' => 10,           // Higher first; jobs of the same priority take turns.
    'delay'    => 3600,         // Or 'run_at' => timestamp.
    'after'    => array( $job_a, $job_b ), // Waits for these; fails if one of them fails or is cancelled.
    'owner'    => $user_id,     // Default: current user. Owners see and control their jobs; finalize() runs as them.
    'group'    => 'import-42',  // Group of jobs, e.g. one user request.
) );

$kit->repeat( 'nightly-sitemap', 'sitemap', fn() => array( 'all' => true ), 'daily' ); // Call on every load.

$kit->job( $id );                 // State, progress, unit counts, result, error, dates.
$kit->jobs( array( 'state' => 'failed', 'type' => 'thumbnail', 'limit' => 20 ) );
$kit->units( $id, 'failed' );     // The failed units with their errors and attempts.
$kit->control( $id, 'pause' );    // pause, resume, cancel, retry (the failed units, or a failed finalization).
$kit->pause_type( 'thumbnail' );  // No new units of this type for anyone; false to resume.
$kit->group( 'import-42' );       // Jobs by state, units (pending, leased, completed, failed, skipped), progress, open.
$kit->control_group( 'import-42', 'cancel' ); // pause, resume, cancel, retry every job of the group that can.
$kit->metrics();                  // By type: counts, completed 1 h / 24 h, failed, avg and p95 ms, per minute.
$kit->workers();                  // Workers seen in the last 5 minutes, with user, capabilities, running units.

add_action( $kit->hook( 'job_completed' ), function ( $job_id, $type, $result ) { /* notify */ }, 10, 3 );
```

Events: `job_created`, `job_completed`, `job_failed`, `job_cancelled`, `unit_completed`, `unit_failed`.

Kit settings: `id` (required), `rest_namespace` (`<id>-worker/v1`), `cli_command` (`<id>-worker`),
`capability` (who may run workers, `manage_options`), `manage_capability` (who sees and controls all jobs and
the metrics), `prune_days` (30), `max_upload` (64 MB), `staging_dir` (uploads folder), `private_files` (false).
Extra roles can be allowed with `save_allowed_roles()` from your own settings screen.

## Runners (JS)

A runner has `prepare( env )` (optional), `run( ctx )` or `runBatch( ctx )`, and `supports()` (optional, false
skips the type on this worker, e.g. without WebGPU or in Node). `ctx` contains `input`, `resume` (last
checkpoint, or null), `artifacts` (files kept by that checkpoint), `engine`, `signal`, `attempt` (from 1),
`checkpoint( data )`, `upload( key, data, type )`, `progress( fraction, text )`, `unit`, and the `env` fields:
`fetch( url, init )` (fetch with the worker's credentials, for the plugin's own REST routes), `report( state,
data )` (a custom status for the page, e.g. a download) and `options` (the host's `workerOptions`).
`upload()` retries network and server errors (4 attempts).

`runBatch( ctx )` replaces `run()` for engines that are faster on batches (ML inference): `ctx.units` is a list
of `{ input, unit, attempt }`, and it returns one outcome per unit, in order: `{ result }` or `{ error, retry }`.

- Throw to fail a unit (retried with backoff). Set `error.retry = false` when retrying cannot help.
- A `WebAssembly.RuntimeError` or Emscripten abort makes the kit discard the engine and `prepare()` again.
- `checkpoint()` marks a point to resume from: a replacement worker gets `resume` and the files uploaded so far.
- `progress()` is shown on the page and sent to the server with the renewals (dashboard, `job()`).
- `assets.js` has `fetchAsset( url, { sha256, onProgress } )` (Cache API, checksum), `loadScript( url )` for
  classic Emscripten glue in module workers, and `compileWasm()`. `capabilities()` from `worker.js` reports
  WebGPU, cores, memory and threads support.

`startWorkers( options )` runs workers in this tab while a page is open (`concurrency`, `stopWhenIdle`,
`onStatus`, `workerType` (`module` or `classic`, for a bundled worker using `importScripts()`), `workerOptions`);
`watchQueue( options )` starts them only when there is waiting work. Pass `types` (the task types its worker
file serves) so that work for other types does not wake it. One tab per browser works for a plugin
at a time (Web Locks). `createDirectWorker( options ).run( type, inputs )` runs inputs right away with the same
runners, outside of the queue (previews, interactive tools), and returns one outcome per input. Types are in
`index.d.ts`.

When the page closes, reloads or navigates away, its workers die with it: the page then gives the units they
were running back to the queue with a beacon (`pagehide`), so another tab or worker can take them within
seconds instead of after `lease_seconds`. `stop()` does the same: each worker submits what it finished and
releases the rest of its batch. The interrupted run does not count as a failed attempt (the last checkpoint
is kept). A crashed tab or a lost connection sends nothing, and its units wait for their lease to expire as
before.

## Headless workers (Node 20+)

For work that must not wait for an open admin page, or for a GPU machine, run the same runners in Node:

```bash
WW_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx" node lib/wasm-worker/js/node-worker.mjs \
    --url https://example.com/wp-json/my-plugin-worker/v1/ --user worker-bot --runners assets/runners.js --concurrency 4
```

It authenticates with an Application Password of a user allowed to run workers, runs `--concurrency`
threads, and stops cleanly on SIGINT/SIGTERM (finished units are submitted, the others released at once). Run it
under systemd, Docker or a CI job. For a local site with its own certificate authority, set `NODE_EXTRA_CA_CERTS`.

Application Passwords need HTTPS (or a `local` environment) and are often turned off by security plugins. Then every request
answers "not allowed": allow them on sites that run headless workers, for a dedicated worker user only.

## Dashboard and WP-CLI

`mountDashboard( element, $kit->dashboard_config() )` shows task types (waiting, running, throughput, p95,
pause button), workers, jobs with progress and actions, and the failed units of each job. Strings can be
translated with `i18n`. Non-managers see their own jobs only.

```bash
wp my-plugin-worker enqueue thumbnail '{"ids":[12,13]}' --priority=5 --key=batch-7
wp my-plugin-worker jobs --state=failed
wp my-plugin-worker units 42 --state=failed
wp my-plugin-worker retry 42            # also: job, pause, resume, cancel
wp my-plugin-worker pause-type thumbnail
wp my-plugin-worker metrics
wp my-plugin-worker workers
wp my-plugin-worker recover             # recovery and planning now
wp my-plugin-worker prune --days=7
```

## REST API

Worker routes (eligible users): `claim`, `renew`, `checkpoint`, `artifact`, `complete`, `fail`, `release`, `waiting`.
Job routes (owner or manager): `GET jobs?group=`, `GET jobs/{id}`, `GET jobs/{id}/units`, `POST jobs/{id} { action }`,
`GET groups/{group}`, `POST groups/{group} { action }` (managers, or the owner of all the jobs of the group).
Operations (manager): `GET metrics`, `GET workers`, `GET types`, `POST types/{type} { action: pause|resume }`.

## Guarantees

- The database is the authority. A batch of units shares a secret lease token; each unit has an epoch that
  increases on every claim. Writes for a unit (checkpoint, upload, result, failure) need the token, the epoch
  and an unexpired lease, checked inside the write, so a worker that comes back late can never overwrite newer work.
  A release (closing page, stopped worker) has the same fence and must come from the user who leased the units.
- At-least-once computation, exactly one accepted result per unit; repeating a completion ID returns its receipt.
- `finalize()` runs as the job's owner (also from cron or another user's request), then the user is restored.
- One request plans a given job at a time (a MySQL named lock), so planning is never done twice.
- Expired leases, interrupted planning, stages and interrupted finalization are recovered in the workers' own
  requests; the hourly cron only tidies up quiet sites.
- `max_leased` and `rate_limit` hold across all workers: claims of a limited type are serialized with a
  MySQL named lock (types without limits claim in parallel).
- Pause gives out no new work (leased units may finish); cancel rejects everything late.
- Two plugins with different kit versions never share classes, tables, routes, options, cron hooks or locks.

## Compared with RabbitMQ, Redis queues and BullMQ

Everything a plugin needs from BullMQ-style queues is here: priorities, delays, repeatable jobs, retries with
backoff, stalled-job recovery, dead letters with retry, deduplication, rate and concurrency limits, pause per
queue, dependencies and multi-step flows, progress, events, metrics, a dashboard and a CLI. The workers are
browsers and Node processes instead of server processes.

Deliberately not included, because WordPress plugins cannot rely on it or do not need it:

- **Broker messaging** (exchanges, routing keys, fan-out, pub/sub, streams). This is a work queue, not a
  message bus; use the action hooks for in-process fan-out.
- **Millisecond latency.** Idle workers poll with backoff (2 to 30 s); a new job starts within seconds, which
  is right for heavy WASM units, not for real-time messaging.
- **Very high throughput.** Each claim is a few indexed MySQL queries: hundreds of units per second, not tens
  of thousands. Make units take seconds or more (batch small ones).
- **Visitor (front-end) workers.** They need task-scoped credentials, rate limits and redundant
  verification of untrusted results; editors' browsers and authenticated Node workers are trusted users.
- **Snapshots of running WASM calls.** A checkpoint is a point the runner can restart from.

## Limits

- Units, payloads, checkpoints and results are JSON up to 256 KB; files go through `upload()` (each up to
  `max_upload`, and the server's `upload_max_filesize`). Split large outputs into several artifact keys.
- A claim looks at the 50 best jobs and scans up to 1,000 claimable units (continuing where it stopped for
  units the user may not run). Millions of finished units are fine; `prune_days` removes old jobs.
- Uploaded files are staged in `staging_dir`. **Plugins with private outputs set `private_files => true` and
  a `staging_dir` outside the web root** (e.g. `dirname( ABSPATH, 2 ) . '/storage'` on Bedrock): uploads are
  then refused while the folder is inside WordPress, `wp-content` or uploads, and `staging_status()` tells
  your settings screen. Without it, the default uploads folder relies on an unguessable folder name, random
  file names without extension (never served as HTML or SVG), and deny files for Apache and IIS; nginx serves
  a file only to someone who already knows its full random URL, which is never sent out. That is fine for
  outputs published anyway (optimized images), not for documents. A job's staged files are deleted when it
  completes without failed units (task option `keep_artifacts` keeps them until the job is pruned).

## Tests

```bash
node tools/vendor.mjs --to /tmp/kit-a --namespace "KitTestA\Worker"
node tools/vendor.mjs --to /tmp/kit-b --namespace "KitTestB\Worker"
wp eval-file tests/smoke.php /tmp/kit-a /tmp/kit-b
npm test
```

`smoke.php`: 141 checks of the PHP side (the lease fence and late writes, releases, uploads, checkpoints, planning,
starvation, two kit copies side by side, scheduling, limits, dependencies, stages, groups, affinity, job data,
finalization as the owner, repeatable jobs, metrics, events and uninstall). `npm test`: the browser runtime in
Node against a fake server (both runner styles, affinity, statuses, credentials, direct runs, release on
stop), and the page side's release beacon on `pagehide` and `stop()`.
`tests/race.php` checks the limits with parallel processes (usage in the file).

Strope Translate uses it for its machine translation (Bergamot in the editors' browsers).

## License

MIT, see `LICENSE`. Plugins that bundle the kit keep this notice with it (for example in their third-party notices).
