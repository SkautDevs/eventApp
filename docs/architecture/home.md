# Home

This document covers Domů, the event's first screen, in each phase of the event.

## Domů

**Domů** (`GET /`, `templates/homepage.twig`) is built by `Module\Home\HomeModel::build()` from the event's `dates`, read on the Prague calendar: `phase` is `before`, `during` or `after` (`null` for an event without dates, rendered as during). Logged out, in every phase, a `.pair-card` at the top (`Už jsi registrovaný na <event>?` … `Přihlásit se`, linking to Profil).

`HomeModel::build()` returns a `Home\View\HomeView`, readonly like its parts under `src/Module/Home/View/`: the `phase` (a string, because the template compares it with one), the date heading, the `Countdown` (before the start only) and the `WindowCard` (only while the registration window is ahead or open), whether the reader is paired, today's notifications, and the `NextUpView` of `NextItem` cards. `Home\ShowAction` renders it with the quick links and the toast as the `HomePage`.

## Before the start

**Before the start**: the install card, then `Co tě čeká` — a window card driven by the provider's `getRegistrationWindow()` (`Přihlašování na programy` with the opening date and time before the window, `Výběr programů` / `Do <end date>` while it is open, with a `Vybrat programy` button when paired and the line `Výběr programů je otevřený. Programy si můžeš prohlédnout v sekci Program.` when not; none without a window) and the countdown `Potkáme se už za N dní` with the event's range (`HomeModel::range()`, `19.–23. 5. 2027`).

## From the start

**From the start** (and after the end): the date heading (`Pátek 21. 5.`, `data-home-date`), today's notifications as full `.news` cards (`NotificationList::today()`, the same markup as Informace; which ones count as today's stays the server's choice), a `Starší notifikace` link to Informace, then `Můj program` (`<section class="next-up" data-next-up>`, `HomeModel`'s `nextUp`, built on `Programs\NextUp::nextUp()`): every programme of the participant is a hidden `.link-card.is-highlight.next-card` with `data-start`/`data-end` instants, `data-time`/`data-until` (written as `Programs\Times::CLOCK`, `9:00`/`do 12:30`) and `data-location`, plus the section's title as a third line; the server hides the box when everything is over, and `www/js/home/nextup.js` shows the running one and the next two (`NEXT_SHOWN = 3`) by the device clock, each with its lead line (`Právě · do 12:30 · Žlutý les`, `Za 25 min · 14:00 · Modrý les` within the hour, else `14:00 · Modrý les`); then the install card. What Domů reads in each phase, and what a failed read does, is in `Home\ShowAction`'s docblock. Each card links to `programy#muj-program-<id>`, which `programs/deeplink.js` opens as Moje scrolled to that programme with its sheet open, and carries `data-deep-link`, which tells the loader to keep that cross-screen hash link inside the app (push the entry with its hash, `go()`, and dispatch `hashchange` when the screen was already loaded) instead of a plain navigation. The highlight links and the colophon close the screen. The emergency box is not on Domů any more; it is Informace's crisis contact.

## The device clock

Domů is rendered for the day it was fetched on and read on others: a copy cached days ago, or one read offline. So its clock-driven parts are drawn again from the device clock by `www/js/home/`. `home/index.js` subscribes three draws to `ui/clock.js`'s `onClockRedraw()`, in this order: `drawNextUp` (`home/nextup.js`), `drawCountdown` and `drawHomeDate` (`home/countdown.js`). They run after `ui/reltime.js` has redrawn the relative times, on every redraw of the clock ([shell-and-loader.md](shell-and-loader.md)). The countdown counts whole Prague days to the start (`den`/`dny`/`dní`) and is hidden from the start on; the date heading is `data-home-date`, in the same words `HomeModel::todayLabel()` uses. The pure parts (`nextLead()`, `shownCards()`, `czechDays()`, `daysUntil()`, `dateLabel()`) are exported and tested in `tests/js/home.test.js`.

The install card that Domů shows is described in [shell-and-loader.md](shell-and-loader.md).
