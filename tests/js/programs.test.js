import {init} from '/js/programs/index.js';
import {
	chipScroll,
	clampZoom,
	formatDay,
	formatNow,
	hashTarget,
	holdsNow,
	hourStep,
	landingScroll,
	listMarks,
	nowOffset,
	returnView,
	tickThreshold,
	zoomScroll,
} from '/js/programs/maths.js';
import {assert, test} from './harness.js';

test('clampZoom keeps the hour between the bounds', () => {
	assert.equal(clampZoom(10, 40, 160), 40);
	assert.equal(clampZoom(200, 40, 160), 160);
	assert.equal(clampZoom(80, 40, 160), 80);
});

test('zoomScroll keeps the moment under the anchor where it is', () => {
	assert.equal(zoomScroll(0, 200, 96, 80, 100), Math.max(96 + (Math.max(0 + 200 - 96, 0) * 100) / 80 - 200, 0));
	assert.equal(zoomScroll(0, 200, 96, 80, 100), 26);
	// an anchor over the stage column is at the axis' start, and the scroll never goes negative
	assert.equal(zoomScroll(0, 50, 96, 80, 100), 46);
	assert.equal(zoomScroll(0, 200, 96, 100, 10), 0);
});

test('tickThreshold is the widest label plus 19, or 64 when nothing measured', () => {
	assert.equal(tickThreshold(45), 64);
	assert.equal(tickThreshold(0), 64);
	assert.equal(tickThreshold(42.5), 61.5);
});

test('hourStep labels every hour from the threshold up, every second one below it', () => {
	assert.equal(hourStep(64, 64), '1');
	assert.equal(hourStep(63.9, 64), '2');
});

test('nowOffset is the hours into the axis, to four decimals', () => {
	const start = Date.parse('2026-09-17T08:00:00+02:00');
	assert.equal(nowOffset(start + 90 * 60000, start), '1.5000');
	assert.equal(nowOffset(start + 60000, start), '0.0167');
});

test('holdsNow includes the start and excludes the end', () => {
	const start = '2026-09-17T08:00:00+02:00';
	const end = '2026-09-17T09:00:00+02:00';
	assert.equal(holdsNow(Date.parse(start), start, end), true);
	assert.equal(holdsNow(Date.parse(end) - 1, start, end), true);
	assert.equal(holdsNow(Date.parse(end), start, end), false);
	assert.equal(holdsNow(Date.parse(start), undefined, undefined), false);
});

test('landingScroll puts now a third of the way into the visible hours', () => {
	assert.equal(landingScroll(3, 80, 412, 96), Math.max(240 - (412 - 96) / 3, 0));
	assert.equal(landingScroll(0.5, 80, 412, 96), 0);
});

test('chipScroll brings the chip into the strip, and says null when it is already there', () => {
	assert.equal(chipScroll(10, 50, 40, 200), 10);
	assert.equal(chipScroll(220, 50, 40, 200), 70);
	assert.equal(chipScroll(60, 50, 40, 200), null);
});

test('listMarks marks what runs and the next start of today only', () => {
	const ms = Date.parse('2026-09-17T10:00:00+02:00');
	const today = 'day-20260917';
	const items = [
		{start: '2026-09-17T09:00:00+02:00', end: '2026-09-17T11:00:00+02:00', day: today},
		{start: '2026-09-17T08:00:00+02:00', end: '2026-09-17T09:00:00+02:00', day: today},
		{start: '2026-09-17T12:00:00+02:00', end: '2026-09-17T13:00:00+02:00', day: today},
		{start: '2026-09-17T14:00:00+02:00', end: '2026-09-17T15:00:00+02:00', day: today},
		{start: '2026-09-18T09:00:00+02:00', end: '2026-09-18T10:00:00+02:00', day: 'day-20260918'},
	];
	assert.deepEqual(listMarks(items, ms, today), ['running', null, 'next', null, null]);
	// the evening before, nothing is next
	assert.deepEqual(listMarks(items.slice(4), ms, today), [null]);
});

test('hashTarget reads the two deep links and nothing else', () => {
	assert.deepEqual(hashTarget('#muj-program-7'), {mine: true, id: 7});
	assert.deepEqual(hashTarget('#section-2-program-7'), {section: 2, id: 7});
	assert.equal(hashTarget('#x'), null);
	assert.equal(hashTarget('#muj-program'), null);
	assert.equal(hashTarget(''), null);
});

test('returnView takes a note for the same programme younger than a minute', () => {
	const now = Date.parse('2026-09-17T10:00:00+02:00');
	assert.equal(returnView({id: '7', view: 'list', at: now - 59000}, 7, now), 'list');
	assert.equal(returnView({id: '7', view: 'overview', at: now - 59000}, '7', now), 'overview');
	assert.equal(returnView({id: '7', view: 'list', at: now - 60000}, 7, now), null);
	assert.equal(returnView({id: '8', view: 'list', at: now - 1000}, 7, now), null);
	assert.equal(returnView({id: '7', view: 'list'}, 7, now), null);
	assert.equal(returnView({id: '7', view: 'nonsense', at: now - 1000}, 7, now), null);
	assert.equal(returnView(null, 7, now), null);
});

test('formatDay and formatNow read the Prague day and clock, not UTC', () => {
	// 23:30 UTC on the 17th is 01:30 on the 18th in Prague
	const ms = Date.parse('2026-09-17T23:30:00Z');
	assert.equal(formatDay(ms), '20260918');
	assert.equal(formatNow(ms), '01:30');
});

test('two Program screens in one page do not share their state', () => {
	const tree = slug =>
		'<div data-pg-root data-view="timeline" data-pg-event="' +
		slug +
		'">' +
		'<div role="tablist"><button type="button" data-pg-view="overview">Přehled</button>' +
		'<button type="button" data-pg-view="timeline">Harmonogram</button></div></div>';
	const host = document.createElement('div');
	host.innerHTML = tree('first') + tree('second');
	document.body.appendChild(host);
	try {
		const [first, second] = host.querySelectorAll('[data-pg-root]');
		init(first);
		init(second);
		assert.equal(first.dataset.pgReady, '1');
		assert.equal(second.dataset.pgReady, '1');
		first.querySelector('[data-pg-view="overview"]').click();
		assert.equal(first.dataset.view, 'overview');
		assert.equal(second.dataset.view, 'timeline');
		assert.equal(second.querySelector('[data-pg-view="timeline"]').getAttribute('aria-selected'), 'true');
	} finally {
		host.remove();
	}
});
