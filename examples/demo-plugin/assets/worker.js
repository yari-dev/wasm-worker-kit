/**
 * Worker module of the demo plugin (runs in a Web Worker).
 */
import { serve } from '../lib/wasm-worker/js/worker.js';
import runners from './runners.js';

serve( { runners } );
