/**
 * The in-browser unit-test harness: tests/Browser/JsUnitTest.php opens runner.html, which
 * loads all.js, which imports every *.test.js and then calls finish(). A test file imports
 * the code under test by its unversioned URL (`/js/ui/reltime.js`). The outcome lands on
 * window.__results = {done, passed, failed: [{name, message}]}, which the Chrome test reads.
 */

const tests = [];

/** Registers a test; fn may return a promise, which finish() waits for. */
export function test(name, fn) {
	tests.push({name, fn});
}

const show = value => {
	try {
		return JSON.stringify(value);
	} catch {
		return String(value);
	}
};

const fail = (message, fallback) => {
	throw new Error(message ? message + ': ' + fallback : fallback);
};

const same = (a, b) => {
	if (Object.is(a, b)) {
		return true;
	}
	if (typeof a !== 'object' || typeof b !== 'object' || a === null || b === null) {
		return false;
	}
	if (Array.isArray(a) !== Array.isArray(b) || Object.getPrototypeOf(a) !== Object.getPrototypeOf(b)) {
		return false;
	}
	const keys = Object.keys(a);
	if (keys.length !== Object.keys(b).length) {
		return false;
	}
	return keys.every(key => Object.hasOwn(b, key) && same(a[key], b[key]));
};

export const assert = {
	/** The two are the same value (Object.is). */
	equal(actual, expected, message) {
		if (!Object.is(actual, expected)) {
			fail(message, 'expected ' + show(expected) + ', got ' + show(actual));
		}
	},
	/** The two have the same structure: arrays and plain objects compared key by key. */
	deepEqual(actual, expected, message) {
		if (!same(actual, expected)) {
			fail(message, 'expected ' + show(expected) + ', got ' + show(actual));
		}
	},
	ok(value, message) {
		if (!value) {
			fail(message, 'expected a truthy value, got ' + show(value));
		}
	},
	/** fn throws; with `expected`, an error that is an instance of it (a class) or whose message matches it (a RegExp). */
	throws(fn, expected, message) {
		try {
			fn();
		} catch (error) {
			if (expected instanceof RegExp && !expected.test(String(error && error.message))) {
				fail(message, 'the error ' + show(String(error && error.message)) + ' does not match ' + String(expected));
			}
			if (typeof expected === 'function' && !(error instanceof expected)) {
				fail(message, 'the error is not a ' + expected.name);
			}
			return;
		}
		fail(message, 'expected the function to throw');
	},
};

/** Runs every registered test in order and publishes the outcome on window.__results. */
export async function finish() {
	const results = {done: false, passed: 0, failed: []};
	for (const {name, fn} of tests) {
		try {
			await fn();
			results.passed++;
		} catch (error) {
			results.failed.push({name, message: String(error && error.message ? error.message : error)});
		}
	}
	results.done = true;
	window.__results = results;
}
