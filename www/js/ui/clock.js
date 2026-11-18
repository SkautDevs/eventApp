/**
 * The clock the screens draw from, and the Prague calendar they read it on. now() is the
 * device clock, or window.pgClock (the browser tests' clock) when that is a function: it
 * is read at each call, because the tests assign it after the page loaded.
 */

import {onScreenMorphed, onScreenShown, onTick} from '../events.js';

export function now() {
	return typeof window.pgClock === 'function' ? window.pgClock() : Date.now();
}

export function two(n) {
	return (n < 10 ? '0' : '') + n;
}

const pragueDate =
	typeof Intl === 'object' && Intl.DateTimeFormat
		? new Intl.DateTimeFormat('en-GB', {timeZone: 'Europe/Prague', year: 'numeric', month: '2-digit', day: '2-digit'})
		: null;

/** "YYYY-MM-DD" of the Prague day an instant falls on, from the parts rather than a locale's printed order. */
export function pragueDay(ms) {
	if (pragueDate === null || !pragueDate.formatToParts) {
		return new Date(ms).toISOString().slice(0, 10);
	}
	const parts = {};
	pragueDate.formatToParts(new Date(ms)).forEach(part => {
		parts[part.type] = part.value;
	});

	return parts.year + '-' + parts.month + '-' + parts.day;
}

const pragueClock =
	typeof Intl === 'object' && Intl.DateTimeFormat
		? new Intl.DateTimeFormat('en-GB', {
				timeZone: 'Europe/Prague',
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
				hour: '2-digit',
				minute: '2-digit',
				hourCycle: 'h23',
			})
		: null;

/**
 * The Prague wall clock of an instant, {year, month, day, hour, minute} as two-digit
 * strings (year in four): what the server's NotificationList::relative() and
 * HomeModel::todayLabel() read, on any phone's zone.
 */
export function pragueParts(ms) {
	if (pragueClock === null || !pragueClock.formatToParts) {
		const local = new Date(ms);
		return {
			year: String(local.getFullYear()),
			month: two(local.getMonth() + 1),
			day: two(local.getDate()),
			hour: two(local.getHours()),
			minute: two(local.getMinutes()),
		};
	}
	const parts = {};
	pragueClock.formatToParts(new Date(ms)).forEach(part => {
		parts[part.type] = part.value;
	});

	return {year: parts.year, month: parts.month, day: parts.day, hour: parts.hour, minute: parts.minute};
}

const subscribers = [];

const redraw = () => {
	const at = now();
	for (const fn of subscribers) {
		fn(at);
	}
};

let started = false;

const start = () => {
	started = true;
	document.addEventListener('DOMContentLoaded', redraw);
	onScreenShown(redraw);
	// a morph writes the server's copy back
	onScreenMorphed(redraw);
	document.addEventListener('visibilitychange', () => {
		if (!document.hidden) {
			redraw();
		}
	});
	// the browser tests' way to move the clock
	onTick(redraw);
	setInterval(redraw, 60000);
};

/**
 * fn(now) on DOMContentLoaded, a screen shown or morphed, a test tick, every minute, and
 * when the document becomes visible again. One set of listeners and one timer serve every
 * subscriber, called in the order they subscribed, all with the same now.
 */
export function onClockRedraw(fn) {
	subscribers.push(fn);
	if (!started) {
		start();
	}
}
