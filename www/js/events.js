/**
 * The DOM events the screens talk through, and the only file that spells their names.
 * Every on…() listens on document, calls fn(event.target, event) and returns a function
 * that removes the listener. Every emit…() dispatches a bubbling CustomEvent on the
 * element it is given; the two loading events, which belong to no screen, go to document.
 */

export const SCREEN_SHOWN = 'screen:shown';
export const SCREEN_HIDDEN = 'screen:hidden';
export const SCREEN_MORPHED = 'screen:morphed';
export const SCREEN_FRESHNESS = 'screen:freshness';
export const SCREEN_LOADING = 'screen:loading';
export const SCREEN_LOADED = 'screen:loaded';
export const SHEET_CLOSED = 'sheet:closed';
export const TICK = 'pg:tick';

const listen = (name, fn) => {
	const handler = event => fn(event.target, event);
	document.addEventListener(name, handler);
	return () => document.removeEventListener(name, handler);
};

const emit = (name, target) => {
	target.dispatchEvent(new CustomEvent(name, {bubbles: true}));
};

export const onScreenShown = fn => listen(SCREEN_SHOWN, fn);
export const onScreenHidden = fn => listen(SCREEN_HIDDEN, fn);
export const onScreenMorphed = fn => listen(SCREEN_MORPHED, fn);
export const onScreenFreshness = fn => listen(SCREEN_FRESHNESS, fn);
export const onScreenLoading = fn => listen(SCREEN_LOADING, fn);
export const onScreenLoaded = fn => listen(SCREEN_LOADED, fn);
export const onSheetClosed = fn => listen(SHEET_CLOSED, fn);
export const onTick = fn => listen(TICK, fn);

export const emitScreenShown = section => emit(SCREEN_SHOWN, section);
export const emitScreenHidden = section => emit(SCREEN_HIDDEN, section);
export const emitScreenMorphed = section => emit(SCREEN_MORPHED, section);
export const emitScreenFreshness = section => emit(SCREEN_FRESHNESS, section);
export const emitSheetClosed = sheet => emit(SHEET_CLOSED, sheet);

// the loader's two, dispatched on document itself and not bubbling: they belong to no screen
export const emitScreenLoading = () => document.dispatchEvent(new CustomEvent(SCREEN_LOADING));
export const emitScreenLoaded = () => document.dispatchEvent(new CustomEvent(SCREEN_LOADED));
