/**
 * The relative time of a sent notification, as NotificationList::relative() says it on the
 * server, with the same bands in the same order: under a minute "právě teď"; under an hour
 * "před N min", even across midnight; the same Prague day "před N h"; the day before
 * "včera HH:MM"; a date before that. Change both together.
 */

import {onClockRedraw, pragueParts} from './clock.js';

// The day before a Prague date, as "YYYY-MM-DD": calendar arithmetic on a UTC midnight,
// so no DST change can move it.
function dayBefore(parts) {
	return new Date(Date.UTC(Number(parts.year), Number(parts.month) - 1, Number(parts.day)) - 86400000).toISOString().slice(0, 10);
}

export function relativeTime(atMs, nowMs) {
	const seconds = Math.floor((nowMs - atMs) / 1000);
	if (seconds < 60) {
		return 'právě teď';
	}
	if (seconds < 3600) {
		return 'před ' + Math.floor(seconds / 60) + ' min';
	}
	const sent = pragueParts(atMs);
	const today = pragueParts(nowMs);
	const sentDay = sent.year + '-' + sent.month + '-' + sent.day;
	if (sentDay === today.year + '-' + today.month + '-' + today.day) {
		return 'před ' + Math.floor(seconds / 3600) + ' h';
	}
	const time = sent.hour + ':' + sent.minute;
	if (sentDay === dayBefore(today)) {
		return 'včera ' + time;
	}

	return Number(sent.day) + '. ' + Number(sent.month) + '. ' + time;
}

/**
 * Every relative time the server wrote with its instant: the notifications on Informace
 * and Domů, and the messages in a programme's sheet. Rendered for the moment they were
 * fetched, they would keep saying "právě teď" in a copy read an hour later. Drawn on
 * every redraw of the clock (onClockRedraw()), subscribed when this module is evaluated.
 */
export function drawSentAt(nowMs) {
	document.querySelectorAll('[data-sent-at]').forEach(el => {
		const at = Date.parse(el.getAttribute('data-sent-at'));
		if (Number.isNaN(at)) {
			return;
		}
		const text = relativeTime(at, nowMs);
		if (el.textContent !== text) {
			el.textContent = text;
		}
	});
}

onClockRedraw(drawSentAt);
