/**
 * The Program screen's arithmetic, with no DOM: the zoom and its anchor, the ruler's
 * density, the now-line, the scroll a landing and a chip need, Moje's marks, the deep
 * links, the view a write returns to, and the event's day and clock.
 */

import {isList} from './daylist.js';

/** How long the note of the view a write was sent from stays good. */
const RETURN_MS = 60000;

export function clampZoom(value, min, max) {
	return Math.min(Math.max(value, min), max);
}

/**
 * The scrollLeft that keeps the moment under `anchor` (px from the box's left edge) where
 * it is when the hour goes from `from` to `to` px wide. The anchor's distance into the
 * time axis grows with the scale, so scrollLeft has to grow with it; the stage column is
 * pinned over the axis, so it is not part of it.
 */
export function zoomScroll(scrollLeft, anchor, stageWidth, from, to) {
	let timeX = Math.max(scrollLeft + anchor - stageWidth, 0);
	timeX *= to / from;
	return Math.max(stageWidth + timeX - anchor, 0);
}

/**
 * How much room one hour has to have for its label to be worth drawing, from the widest
 * rendered label: 5px is .tl-tick's own padding before the text, 14px the gap that keeps
 * two labels clearly apart rather than merely not touching. That lands the threshold at
 * 61px for Montserrat and 54px for themix — so both events still label every hour at the
 * 64px step and both thin at the 51px one below it, which is where a label and its
 * neighbour come within 5px of colliding. 64 while nothing has been measured.
 */
export function tickThreshold(widestLabelPx) {
	return widestLabelPx > 0 ? widestLabelPx + 19 : 64;
}

/**
 * Below the threshold only every second hour keeps its text. One step is enough: at the
 * 40px floor that leaves 80px between labels, twice what a label needs, so a third step
 * would thin the axis for no gain.
 */
export function hourStep(hourWidth, reach) {
	return hourWidth >= reach ? '1' : '2';
}

/** The hours from the axis' start to `ms`, as the --now-offset string (four decimals). */
export function nowOffset(ms, axisStartMs) {
	return ((ms - axisStartMs) / 3600000).toFixed(4);
}

/** Whether `ms` is inside [start, end): the start counts, the end does not. */
export function holdsNow(ms, startIso, endIso) {
	const start = Date.parse(startIso);
	const end = Date.parse(endIso);
	return ms >= start && ms < end;
}

/** The scrollLeft that puts now (`offsetHours` into the axis) a third of the way into the visible hours. */
export function landingScroll(offsetHours, hourWidth, clientWidth, stageWidth) {
	return Math.max(offsetHours * hourWidth - (clientWidth - stageWidth) / 3, 0);
}

/** The strip's scrollLeft that brings a chip at `left`, `width` wide, into view; null when it is in view already. */
export function chipScroll(left, width, scrollLeft, clientWidth) {
	const right = left + width;
	if (left < scrollLeft) {
		return left;
	}
	if (right > scrollLeft + clientWidth) {
		return right - clientWidth;
	}
	return null;
}

/**
 * Můj program's marks, one per item ({start, end, day}): 'running' for what is running,
 * 'next' for the next thing to start — only on the day that is today, so a list read the
 * evening before shows nothing — and null for the rest.
 */
export function listMarks(items, ms, todayKey) {
	let next = false;
	return items.map(item => {
		if (holdsNow(ms, item.start, item.end)) {
			return 'running';
		}
		if (Date.parse(item.start) > ms && !next && item.day === todayKey) {
			next = true;
			return 'next';
		}
		return null;
	});
}

/**
 * The deep link into one programme: #muj-program-<id> (the homepage's next-programme card,
 * on Můj program) as {mine: true, id}, #section-<s>-program-<id> as {section, id}; null for
 * any other hash.
 */
export function hashTarget(hash) {
	const mine = hash.match(/^#muj-program-(\d+)$/);
	if (mine) {
		return {mine: true, id: Number(mine[1])};
	}
	const matches = hash.match(/^#section-(\d+)-program-(\d+)$/);
	if (matches) {
		return {section: Number(matches[1]), id: Number(matches[2])};
	}
	return null;
}

/**
 * The view a write for programme `id` was sent from, from the stored note: null without a
 * note, for another programme, for one older than a minute, or for a view that is none.
 */
export function returnView(note, id, nowMs) {
	if (!note || note.id !== String(id) || !(nowMs - note.at < RETURN_MS)) {
		return null;
	}

	return isList(note.view) || note.view === 'timeline' ? note.view : null;
}

let dayFormat = null;

/**
 * "20260917" for the event's calendar day of `ms`, matching the day keys. Built from the
 * parts rather than from a locale's printed order, which no locale promises to keep.
 */
export function formatDay(ms) {
	try {
		dayFormat = dayFormat || new Intl.DateTimeFormat('en-US', {timeZone: 'Europe/Prague', year: 'numeric', month: '2-digit', day: '2-digit'});
		const parts = {};
		dayFormat.formatToParts(ms).forEach(part => {
			parts[part.type] = part.value;
		});
		return parts.year + parts.month + parts.day;
	} catch (_e) {
		const d = new Date(ms);
		return String(d.getFullYear()) + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0');
	}
}

let nowFormat = null;

/** "14:05", the now-line's label, on the event's clock. */
export function formatNow(ms) {
	try {
		nowFormat = nowFormat || new Intl.DateTimeFormat('cs-CZ', {timeZone: 'Europe/Prague', hour: '2-digit', minute: '2-digit', hour12: false});
		return nowFormat.format(ms);
	} catch (_e) {
		// no Intl time zone support: the phone's own clock is the best there is
		const d = new Date(ms);
		return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
	}
}
