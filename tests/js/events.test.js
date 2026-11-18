import {
	emitScreenFreshness,
	emitScreenHidden,
	emitScreenLoaded,
	emitScreenLoading,
	emitScreenMorphed,
	emitScreenShown,
	emitSheetClosed,
	onScreenFreshness,
	onScreenHidden,
	onScreenLoaded,
	onScreenLoading,
	onScreenMorphed,
	onScreenShown,
	onSheetClosed,
	onTick,
	SCREEN_FRESHNESS,
	SCREEN_HIDDEN,
	SCREEN_LOADED,
	SCREEN_LOADING,
	SCREEN_MORPHED,
	SCREEN_SHOWN,
	SHEET_CLOSED,
	TICK,
} from '/js/events.js';
import {assert, test} from './harness.js';

test('the eight event names', () => {
	assert.deepEqual(
		[SCREEN_SHOWN, SCREEN_HIDDEN, SCREEN_MORPHED, SCREEN_FRESHNESS, SCREEN_LOADING, SCREEN_LOADED, SHEET_CLOSED, TICK],
		['screen:shown', 'screen:hidden', 'screen:morphed', 'screen:freshness', 'screen:loading', 'screen:loaded', 'sheet:closed', 'pg:tick'],
	);
});

const pairs = [
	['screen:shown', onScreenShown, emitScreenShown],
	['screen:hidden', onScreenHidden, emitScreenHidden],
	['screen:morphed', onScreenMorphed, emitScreenMorphed],
	['screen:freshness', onScreenFreshness, emitScreenFreshness],
	['sheet:closed', onSheetClosed, emitSheetClosed],
];

for (const [name, on, emit] of pairs) {
	test(name + ': the handler receives the section, and the returned function removes it', () => {
		const section = document.createElement('section');
		document.body.appendChild(section);
		const seen = [];
		const off = on((target, event) => seen.push([target, event.type, event.bubbles]));
		emit(section);
		off();
		emit(section);
		section.remove();
		assert.equal(seen.length, 1);
		assert.equal(seen[0][0], section);
		assert.equal(seen[0][1], name);
		assert.equal(seen[0][2], true);
	});
}

test('the two loading events are dispatched on document', () => {
	const seen = [];
	const offLoading = onScreenLoading(target => seen.push(['loading', target]));
	const offLoaded = onScreenLoaded(target => seen.push(['loaded', target]));
	emitScreenLoading();
	emitScreenLoaded();
	offLoading();
	offLoaded();
	emitScreenLoading();
	emitScreenLoaded();
	assert.deepEqual(seen, [
		['loading', document],
		['loaded', document],
	]);
});

test('onTick hears a pg:tick on document', () => {
	let calls = 0;
	const off = onTick(() => calls++);
	document.dispatchEvent(new CustomEvent(TICK));
	off();
	document.dispatchEvent(new CustomEvent(TICK));
	assert.equal(calls, 1);
});
