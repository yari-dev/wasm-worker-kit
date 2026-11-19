/**
 * Types of the WASM worker kit's browser SDK.
 */

/* worker.js */

/** What every runner function gets. */
export interface RunnerEnv {
	/** Fetch with the worker's credentials (nonce and cookie, or the headless worker's Authorization header) for URLs of the site. */
	fetch( url: string, init?: RequestInit ): Promise<Response>;
	/** Posts a custom status to the page, e.g. `report( 'downloading', { loaded, total } )`. */
	report( state: string, data?: Record<string, unknown> ): void;
	/** The host's `workerOptions`. */
	options: Record<string, unknown>;
}

export interface UnitContext<Input = unknown, Checkpoint = unknown, Engine = unknown> extends RunnerEnv {
	/** Unit payload from the server (`Task::payload()`). */
	input: Input;
	/** Last checkpoint of this unit, null at the first attempt. */
	resume: Checkpoint | null;
	/** Files kept by that checkpoint. */
	artifacts: ArtifactReceipt[];
	/** What `prepare()` returned. */
	engine: Engine;
	/** Aborted when the lease is lost or the worker stops. */
	signal: AbortSignal;
	/** Attempt number, from 1. */
	attempt: number;
	/** Null in a direct run. */
	unit: { id: number; key: string; jobId: number } | null;
	/** Saves a point to resume from; keeps the files uploaded so far. */
	checkpoint( data: Checkpoint ): Promise<{ sequence: number; duplicate: boolean }>;
	/** Uploads a file for this unit (checksum computed and checked). */
	upload( key: string, data: Blob | ArrayBuffer | ArrayBufferView, type?: string ): Promise<ArtifactReceipt>;
	/** Reports progress (0 to 1): shown on the page, sent to the server with the renewals. */
	progress( fraction: number, text?: string ): void;
}

export interface ArtifactReceipt {
	key: string;
	size: number;
	sha256: string;
}

export type Outcome<Result = unknown> = { result: Result } | { error: string; retry?: boolean };

export interface BatchContext<Input = unknown, Engine = unknown> extends RunnerEnv {
	units: { input: Input; unit: { id: number; key: string; jobId: number } | null; attempt: number }[];
	engine: Engine;
	signal: AbortSignal;
	/** Progress of the whole batch (0 to 1). */
	progress( fraction: number, text?: string ): void;
}

export interface Runner<Input = unknown, Result = unknown, Checkpoint = unknown, Engine = unknown> {
	/** False when this environment cannot run the task (no WebGPU, no OffscreenCanvas...). */
	supports?(): boolean;
	/** Loads the engine once; called again after a WebAssembly crash. */
	prepare?( env: RunnerEnv ): Promise<Engine> | Engine;
	/** Runs one unit and returns its JSON result. Throw to fail it; set `error.retry = false` when retrying cannot help. */
	run?( ctx: UnitContext<Input, Checkpoint, Engine> ): Promise<Result> | Result;
	/** Instead of `run()`: runs all the units of a batch at once; one outcome per unit, in order. */
	runBatch?( ctx: BatchContext<Input, Engine> ): Promise<Outcome<Result>[]> | Outcome<Result>[];
}

export function serve( options: { runners: Record<string, Runner<any, any, any, any>> } ): void;

export function capabilities(): { webgpu: boolean; cores: number; memory: number; threads: boolean; node: boolean };

/* host.js */

export interface ClientConfig {
	id: string;
	restUrl: string;
	nonce: string;
	nonceUrl: string;
	workerUrl: string;
}

export interface WorkerStatus {
	state: 'idle' | 'preparing' | 'working' | 'progress' | 'completed' | 'unit-failed' | 'lease-lost' | 'other-tab' | 'unsupported' | 'error' | 'stopped';
	/** Task type of the batch (working, progress, completed...). */
	task?: string;
	worker?: number;
	[ key: string ]: unknown;
}

export interface WorkerOptions extends ClientConfig {
	/** `module` (default) or `classic`, for a bundled worker that uses importScripts(). */
	workerType?: 'module' | 'classic';
	/** JSON data for the runners (`options` in their context). */
	workerOptions?: Record<string, unknown>;
	/** Workers in this tab. Default 1. */
	concurrency?: number;
	/** Stop after this many empty claims in a row, 0 for never. */
	stopWhenIdle?: number;
	onStatus?( status: WorkerStatus ): void;
}

export interface WorkerController {
	/** Starts working in this tab once no other tab of the browser does; false if another works and `wait` is false. */
	start( wait?: boolean ): Promise<boolean>;
	stop(): void;
	isRunning(): boolean;
}

export function isSupported(): boolean;
export function startWorkers( options: WorkerOptions ): WorkerController;
export function watchQueue( options: WorkerOptions & { interval?: number; types?: string[] } ): { stop(): void; workers: WorkerController };
export function createDirectWorker( options: Omit<WorkerOptions, 'concurrency' | 'stopWhenIdle'> ): {
	/** Runs inputs right away, outside of the queue; one outcome per input. */
	run<Result = unknown>( type: string, inputs: unknown[] ): Promise<Outcome<Result>[]>;
	close(): void;
};

/* assets.js */

export function fetchAsset( url: string, options?: { sha256?: string; cache?: string; onProgress?( loaded: number, total: number ): void } ): Promise<Uint8Array>;
export function loadScript( url: string ): Promise<void>;
export function compileWasm( key: string, bytes: Uint8Array ): Promise<WebAssembly.Module>;

/* dashboard.js */

export function mountDashboard(
	root: HTMLElement,
	options: { restUrl: string; nonce: string; canManage: boolean; interval?: number; i18n?: Record<string, string> }
): { refresh(): Promise<void>; destroy(): void };
