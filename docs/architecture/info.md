# Info

This document covers Informace: the crisis contact, the card to the message form, the notifications readers see, the quick links and the toast Informace shows.

## Informace

`InfoModule` (`/informace`, tab `Informace`, `templates/info.twig`) is the crisis contact, then `Notifikace` — the message log as readers see it: every visible message, newest first, under Prague day headings (`Push\NotificationList::groups()`, `Čt 20. 5.`, keyed for the morph), each card left-aligned with its full body and a relative time (`NotificationList::relative()`: under a minute `právě teď`, under 60 minutes always `před N min`, even across midnight; the same Prague day `před N h`; the day before `včera H:i`; older a date), `Zatím žádné notifikace.` when there is none — then `Rychlé odkazy` (the handbook and `content/links.php`). Who sees a programme's message here is in `Info\ShowAction`'s docblock: everybody reads it in that programme's sheet on `/programy`. Every relative time carries its instant in `data-sent-at`, here, on Domů and in a programme's sheet, and `www/js/ui/reltime.js` redraws it from the device clock with the same bands, so a copy read later or offline still says how long ago it is. There is no archive or detail screen. Because of that `info` requires `push`: `EventConfig::load()` refuses an event that enables one without the other, and every shipped event carries both.

The cards are `Push\Notification`s, grouped by day into `NotificationGroup`s (`key` for the morph, `label` the heading), and `Info\ShowAction` renders them with the crisis contact, the quick links, the inbox flag and a waiting toast as the `Info\View\InfoPage`.

## The card to the message form

An event with `inbox` (obrok19 and obrok27) shows one more card directly under the crisis contact and above `Notifikace`: `Napiš organizátorům`, a `.link-card.info-inbox` with `data-key="inbox-card"` and no icon, outside the quick links' list (`www/css/info.css` gives it the list's gutter and the contact's distance from the heading). Its note is `Dotaz, problém nebo nápad. Když jde o zdraví nebo bezpečí, volej krizový kontakt.`. A paired reader's card links to the form page `/<slug>/zpravy`. A logged-out reader's card links to Profil and has a second note, `Napsat můžeš po přihlášení.`. An event without a crisis contact (obrok19) shows the card first on the screen. The form, the send and the organisers' side are in [inbox.md](inbox.md).

## The toast

Informace takes a waiting server toast (`Toast\Toasts::take()`) on a full page and renders it on top, as Profil and Program do, and marks the response (`X-Toast: 1`) so the worker does not store it. The one toast that lands here is the sent message's, `Zpráva odeslána, díky!`, after the redirect to `/<slug>/informace#odeslano` ([inbox.md](inbox.md)). `ShowAction` takes the toast before it reads the participant, because that read clears a waiting toast when it finds the session gone. Informace leaves no `gone()` toast of its own: Profil, not Informace, explains a session that kissj no longer knows.

## Quick links and the crisis contact

`content/links.php` is Informace's `Rychlé odkazy`: a `<ul class="link-list">` of `.link-card`s (the handbook first and tonal, a `highlight` entry tonal too, an optional `note` as a second line, an outward mark on absolute URLs, which open in a new tab — `target="_blank" rel="noopener"`, as do Domů's external `highlight` CTAs and its footer link), while Domů shows only the `highlight` ones as `.highlight` CTAs. `content/emergency.php` (`contacts`: `label`, `phone`, optional `note`; `info`: lines) is Informace's crisis contact (`Krizový kontakt akce`, each contact with a `Zavolat` button that is a `tel:` link, spaces stripped, so it dials from the worker's offline copy); an event without the file shows none; obrok27 carries the real crisis line `+420 704 444 015`; navigamus27 and korbo26 have published none, so theirs is the general `112` until they do. Quick links (`content/links.php`): obrok19, obrok27, navigamus27 (its site and `info@navigamus.cz`) and korbo26 (its site, FAQ and Instagram).

`EventConfig::links()` reads `links.php` as a list of `Event\Link` (`label`, `href`, `icon`, `suffix`, `highlight`, `note`), `[]` without the file, and `emergency()` reads `emergency.php` as an `Event\Emergency`, null without the file.
