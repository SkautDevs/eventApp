/**
 * The current-time line. The axis of each page is two instants (data-axis-start,
 * data-axis-end, with their offset), so placing "now" is a subtraction whatever
 * zone the phone is set to; only the label is formatted, in the event's zone.
 * What it writes — data-now and --now-offset on a .tl-page — the server never
 * writes, so a background morph leaves it alone, and afterMorph() re-runs it anyway.
 * Můj program's items are marked in the same pass. window.pgClock (a function
 * returning ms) stands in for the clock in the browser tests (ui/clock.js's now()),
 * and a test tick re-runs the update at once.
 */

import {onScreenShown, onTick} from '../events.js';
import {now} from '../ui/clock.js';
import {setAttr, setText} from '../ui/dom.js';
import {formatDay, formatNow, holdsNow, landingScroll, listMarks, nowOffset} from './maths.js';

export function setup(ctx) {
	const {root} = ctx;

	/** The timeline page whose axis holds `ms`, or null. */
	function pageAt(ms) {
		return ctx.panels('timeline').find(page => holdsNow(ms, page.dataset.axisStart, page.dataset.axisEnd)) || null;
	}

	ctx.updateNow = () => {
		const ms = now();
		// Můj program: what is running is marked, and the next thing to start carries
		// the "teď" line above it — only on the day that is today, so a list read the
		// evening before shows nothing.
		const todayKey = 'day-' + formatDay(ms);
		const items = Array.from(root.querySelectorAll('.view-list .pl-item'));
		const marks = listMarks(
			items.map(item => {
				const day = item.closest('.pl-day');
				return {start: item.dataset.start, end: item.dataset.end, day: day ? day.dataset.key : null};
			}),
			ms,
			todayKey,
		);
		items.forEach((item, i) => {
			const state = marks[i];
			if (state === null) {
				if (item.hasAttribute('data-now')) {
					item.removeAttribute('data-now');
				}
			} else {
				setAttr(item, 'data-now', state);
			}
		});
		const today = pageAt(ms);
		ctx.panels('timeline').forEach(page => {
			if (page !== today && page.hasAttribute('data-now')) {
				page.removeAttribute('data-now');
				page.style.removeProperty('--now-offset');
			}
		});
		if (!today) {
			return;
		}
		const offset = nowOffset(ms, Date.parse(today.dataset.axisStart));
		if (today.style.getPropertyValue('--now-offset') !== offset) {
			today.style.setProperty('--now-offset', offset);
		}
		setAttr(today, 'data-now', '');
		const label = today.querySelector('[data-pg-now-label]');
		if (label) {
			setText(label, formatNow(ms));
		}
	};

	/**
	 * On the first show during the event, the timeline opens on today's page — even
	 * from a copy cached days ago, whose server-chosen page is stale — with "now" a
	 * third of the way into the visible hours. Once; never after a morph or a deep
	 * link, and never when the reader has already moved.
	 */
	ctx.openOnNow = () => {
		const ms = now();
		const today = pageAt(ms);
		if (!today) {
			// Before the day's first programme or after its last one, now is off the
			// page's axis, but the day is still today: open its page, draw nothing
			// (updateNow() finds no axis holding now) and leave the scroll alone.
			ctx.showPageByKey('timeline', 'page-' + formatDay(ms));
			return;
		}
		ctx.showPageByKey('timeline', today.dataset.key);
		const box = today.querySelector('.tl-scroll');
		if (!box || box.clientWidth === 0) {
			return;
		}
		// reads back the --now-offset updateNow() has just written
		const offset = parseFloat(today.style.getPropertyValue('--now-offset')) || 0;
		box.scrollLeft = landingScroll(offset, ctx.hourWidth, box.clientWidth, ctx.stageWidth);
	};

	onTick(() => {
		if (document.contains(root)) {
			ctx.updateNow();
		}
	});
	// a minute is the line's resolution; checking twice a minute keeps it within half of one
	window.setInterval(() => {
		if (document.contains(root) && !root.closest('[data-screen][hidden]')) {
			ctx.updateNow();
		}
	}, 30000);
	document.addEventListener('visibilitychange', () => {
		if (!document.hidden && document.contains(root)) {
			ctx.updateNow();
		}
	});
	// a screen shown again after a while has a line that stood still meanwhile
	onScreenShown(target => {
		if (target.contains && target.contains(root)) {
			ctx.updateNow();
		}
	});
}
