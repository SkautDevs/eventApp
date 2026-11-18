/** Two-finger pinch geometry, shared by the Program timeline and the site plan. */

/** The distance between two {x, y} points and their midpoint. */
export function span(a, b) {
	return {dist: Math.hypot(a.x - b.x, a.y - b.y), midX: (a.x + b.x) / 2, midY: (a.y + b.y) / 2};
}
