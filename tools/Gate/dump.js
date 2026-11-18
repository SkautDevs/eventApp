/*
 * The render gate's page-side dump (tools/Gate/RenderGate.php runs it through WebDriver and
 * returns its value): one line per element of <html>, <body> and everything under <body>, in
 * document order,
 *
 *   <path> | <x>,<y>,<w>,<h> | <prop>:<value>;…
 *
 * where the path is tag names with :nth-of-type from <html> (plus #id), the box is
 * getBoundingClientRect() rounded to 2 decimals, and the properties are the computed ones
 * that differ from a bare element of the same tag in an empty same-origin iframe, sorted by
 * name. ::before and ::after get a line of their own (box `-`) when their content is not
 * `none`. A subtree whose root computes display: none is recorded by its root line alone.
 * <head> is left out. A custom property (--*) is listed only where its computed value
 * differs from the parent's (the originating element's, for a pseudo-element; the bare
 * iframe's root, for <html>), i.e. where it is declared or overridden: a token moved or
 * dropped shows on the element that declares it, without every inherited token repeated
 * on every line.
 *
 * The file is one expression; RenderGate prefixes it with `return`.
 */
(function () {
	'use strict';

	var PSEUDOS = ['::before', '::after'];

	function round(value) {
		var rounded = Math.round(value * 100) / 100;

		return rounded === 0 ? 0 : rounded;
	}

	function keyOf(element) {
		return (element.namespaceURI || '') + ' ' + element.localName;
	}

	function copy(style) {
		var values = {};
		for (var i = 0; i < style.length; i++) {
			var name = style[i];
			if (name.indexOf('--') !== 0) {
				values[name] = style.getPropertyValue(name);
			}
		}

		return values;
	}

	var elements = [document.documentElement];
	if (document.body) {
		elements.push(document.body);
		document.body.querySelectorAll('*').forEach(function (element) {
			elements.push(element);
		});
	}

	// The defaults are read first and the iframe removed again before anything of the page
	// is measured, so the page is dumped exactly as it stood.
	var defaults = {};
	// the custom properties of a bare root, against which <html>'s own are read
	var rootCustoms = {};
	var frame = document.createElement('iframe');
	frame.setAttribute('aria-hidden', 'true');
	frame.style.cssText = 'position:fixed;left:-20000px;top:0;border:0;visibility:hidden;width:'
		+ window.innerWidth + 'px;height:' + window.innerHeight + 'px';
	document.documentElement.appendChild(frame);
	try {
		var blank = frame.contentDocument;
		blank.open();
		blank.write('<!doctype html><html><head></head><body></body></html>');
		blank.close();
		var view = blank.defaultView;
		var rootStyle = view.getComputedStyle(blank.documentElement);
		for (var r = 0; r < rootStyle.length; r++) {
			if (rootStyle[r].indexOf('--') === 0) {
				rootCustoms[rootStyle[r]] = rootStyle.getPropertyValue(rootStyle[r]);
			}
		}
		elements.forEach(function (element) {
			var key = keyOf(element);
			if (defaults[key]) {
				return;
			}
			var bare = blank.createElementNS(element.namespaceURI, element.localName);
			blank.body.appendChild(bare);
			defaults[key] = {'': copy(view.getComputedStyle(bare))};
			PSEUDOS.forEach(function (pseudo) {
				defaults[key][pseudo] = copy(view.getComputedStyle(bare, pseudo));
			});
			blank.body.removeChild(bare);
		});
	} finally {
		frame.remove();
	}

	/**
	 * A standard property is listed where it differs from the bare element's value, a
	 * custom one where it differs from the inherited value: `parent` is the parent's
	 * computed style, or an object of the bare root's custom properties.
	 */
	function properties(style, base, parent) {
		var names = [];
		for (var i = 0; i < style.length; i++) {
			var name = style[i];
			var value = style.getPropertyValue(name);
			if (name.indexOf('--') === 0) {
				var inherited = typeof parent.getPropertyValue === 'function' ? parent.getPropertyValue(name) : parent[name];
				if (value !== (inherited === undefined ? '' : inherited)) {
					names.push(name);
				}
			} else if (value !== base[name]) {
				names.push(name);
			}
		}
		names.sort();

		return names.map(function (name) {
			return name + ':' + style.getPropertyValue(name);
		}).join(';');
	}

	var paths = new Map();

	function pathOf(element) {
		if (element === document.documentElement) {
			return 'html';
		}
		var parent = element.parentElement;
		var index = 1;
		for (var sibling = element.previousElementSibling; sibling; sibling = sibling.previousElementSibling) {
			if (sibling.localName === element.localName && sibling.namespaceURI === element.namespaceURI) {
				index++;
			}
		}
		var path = paths.get(parent) + ' > ' + element.localName + ':nth-of-type(' + index + ')'
			+ (element.id ? '#' + element.id : '');
		paths.set(element, path);

		return path;
	}

	paths.set(document.documentElement, 'html');
	var lines = [];
	// in document order, a display: none subtree is contiguous: skipped until it ends
	var skipped = null;
	elements.forEach(function (element) {
		if (skipped !== null && skipped.contains(element)) {
			return;
		}
		skipped = null;
		var path = pathOf(element);
		var style = getComputedStyle(element);
		var base = defaults[keyOf(element)];
		var parent = element === document.documentElement ? rootCustoms : getComputedStyle(element.parentElement);
		var box = element.getBoundingClientRect();
		lines.push(path + ' | ' + [box.x, box.y, box.width, box.height].map(round).join(',')
			+ ' | ' + properties(style, base[''], parent));
		if (style.getPropertyValue('display') === 'none') {
			skipped = element;

			return;
		}
		PSEUDOS.forEach(function (pseudo) {
			var pseudoStyle = getComputedStyle(element, pseudo);
			if (pseudoStyle.getPropertyValue('content') !== 'none') {
				lines.push(path + pseudo + ' | - | ' + properties(pseudoStyle, base[pseudo], style));
			}
		});
	});

	return lines.join('\n') + '\n';
})();
