import './worker.js';
import './netstate.js';
import './toast.js';
import './confirm.js';
import './progress.js';
import './install.js';
import '../ui/reltime.js';
import {startLoader} from '../loader/loader.js';
import {scheduleWarm} from '../loader/warmup.js';

if (startLoader()) {
	scheduleWarm();
}
