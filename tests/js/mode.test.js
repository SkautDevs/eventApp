import {resolveMode} from '/js/profile/mode.js';
import {assert, test} from './harness.js';

test('resolveMode: a stored light or dark is the mode, whatever the system prefers', () => {
	assert.equal(resolveMode('light', true), 'light');
	assert.equal(resolveMode('dark', false), 'dark');
});

test('resolveMode: system follows the device', () => {
	assert.equal(resolveMode('system', true), 'dark');
	assert.equal(resolveMode('system', false), 'light');
});
