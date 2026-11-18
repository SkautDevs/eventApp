/** The screen the reader sees: the one section of <main> that is not hidden. */

export function visibleScreen() {
	return document.querySelector('main > [data-screen]:not([hidden])');
}
