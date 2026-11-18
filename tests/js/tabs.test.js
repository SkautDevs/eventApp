import {markSelected, nextTabIndex} from '/js/ui/tabs.js';
import {assert, test} from './harness.js';

test('the arrow keys wrap at both ends', () => {
	assert.equal(nextTabIndex('ArrowRight', 0, 3), 1);
	assert.equal(nextTabIndex('ArrowRight', 2, 3), 0);
	assert.equal(nextTabIndex('ArrowLeft', 2, 3), 1);
	assert.equal(nextTabIndex('ArrowLeft', 0, 3), 2);
});

test('Home and End go to the first and the last tab', () => {
	assert.equal(nextTabIndex('Home', 2, 3), 0);
	assert.equal(nextTabIndex('End', 0, 3), 2);
});

test('any other key is not a tab move', () => {
	assert.equal(nextTabIndex('Enter', 1, 3), null);
	assert.equal(nextTabIndex('ArrowDown', 1, 3), null);
});

test('markSelected sets aria-selected and the roving tabindex', () => {
	const tab = document.createElement('button');
	markSelected(tab, true, false);
	assert.equal(tab.getAttribute('aria-selected'), 'true');
	assert.equal(tab.tabIndex, 0);
	markSelected(tab, false, true);
	assert.equal(tab.getAttribute('aria-selected'), 'false');
	assert.equal(tab.tabIndex, -1);
});
