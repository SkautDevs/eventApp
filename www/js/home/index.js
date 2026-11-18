/**
 * Domů is rendered for the day it was fetched on, and read on others: a copy cached days
 * ago or read offline. Its clock-driven parts (the next programmes, the countdown, the date
 * heading) are therefore drawn from the device clock, on every redraw of ui/clock.js:
 * window.pgClock is the browser tests' clock, and pg:tick their way to make it move. Every
 * notification's relative time is ui/reltime.js's, subscribed before these.
 */

import {onClockRedraw} from '../ui/clock.js';
import {drawCountdown, drawHomeDate} from './countdown.js';
import {drawNextUp} from './nextup.js';

onClockRedraw(drawNextUp);
onClockRedraw(drawCountdown);
onClockRedraw(drawHomeDate);
