/**
 * The two-tap confirmations. A button carrying data-confirm (the logout on Profil, the
 * unregister in a programme's sheet) does not submit on the first tap: it swaps its label
 * for the question and the second tap submits. A page shown again from the back/forward
 * cache, a screen left, or a programme's sheet closed starts over at the first tap.
 */

import {onScreenHidden, onSheetClosed} from '../events.js';

function resetConfirms(scope) {
	scope.querySelectorAll('[data-confirm][data-confirming]').forEach(button => {
		button.textContent = button.getAttribute('data-label');
		button.removeAttribute('data-confirming');
	});
}

document.addEventListener('click', event => {
	const button = event.target.closest ? event.target.closest('[data-confirm]') : null;
	if (!button || button.hasAttribute('data-confirming')) {
		// not one of ours, or the second tap: the form submits as it always would
		return;
	}
	event.preventDefault();
	button.setAttribute('data-label', button.textContent);
	button.setAttribute('data-confirming', '');
	button.textContent = button.getAttribute('data-confirm');
});
onScreenHidden(scope => {
	resetConfirms(scope);
});
onSheetClosed(scope => {
	resetConfirms(scope);
});
window.addEventListener('pageshow', () => {
	resetConfirms(document);
});
