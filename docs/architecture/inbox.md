# Inbox

This document covers `Napiš organizátorům`: the feature flag and its content file, the Zprávy view of Informace and its send, the offline behaviour and the draft, the `inbox_messages` table, and the inbox section of the admin page.

## The feature and its content file

`inbox` is a feature of its own, served by `Inbox\InboxModule` (`src/Module/Inbox/`, `www/js/inbox/`, `www/css/inbox.css`). It is listed in `ModuleRegistry::MODULES` and in `ORDER` right after `InfoModule`, so its routes, its script and its sheet follow Informace's. The module has no tab (`menuItem()` is null) and no page of its own: it adds the Zprávy view and the send route to the Informace screen, and the only way in is that screen's `Zprávy` pill. It is opt-in per event, and off by default. obrok19 and obrok27 enable it.

`EventConfig::load()` refuses an event that enables `inbox` without `info`, because the form is a view of Informace ([bootstrap-and-config.md](bootstrap-and-config.md)). `info` requires `push`, so every event with `inbox` has the admin page too.

The promise is optional. An event may have `events/<slug>/content/inbox.php` returning `['promise' => '<one sentence>']` on how fast its organisers read the `help` messages, and `EventConfig::inboxPromise()` reads it, trimmed; it is null without the file or when the promise is missing or blank. With a promise, it is the line under the `help` kind on the form and the second line of the toast after a `help` message. Without one, the form renders no promise element at all (and the `help` radio no `aria-describedby`), and the toast after a `help` message is the title alone, as after an `idea`. Neither obrok19 nor obrok27 has a promise; the `inbox` fixture event has one, so both paths are tested.

The code name is `inbox` because the table name `messages` is the notification log's. What a participant sends is a **zpráva** in the UI, never a notifikace, which stays the organisers' word for their own messages.

## The Zprávy view of Informace

The form is the second view of the Informace screen, switched like Místa's `Mapa | Plán` ([map.md](map.md) "Two addresses, one screen"; Informace itself is in [info.md](info.md)). `GET /<slug>/informace` (route `info`, argument `view=info`) and `GET /<slug>/informace/zpravy` (route `inbox`, arguments `view=inbox` and `screen=/informace`) are both `Info\ShowAction`; `InboxModule` registers the second, so it exists only with `inbox` (`/zpravy`, the form's first address, was never deployed and is gone). `Http\ScreenAliasMiddleware` renders it as the one Informace screen (`data-screen="/<slug>/informace"` on both), and `InfoModule` gives the Informace tab's `MenuItem` the alias `inbox` exactly when the event enables `inbox`, so the tab carries `data-screen-aliases="/<slug>/informace/zpravy"` and lights on both addresses, and the precache list maps the address to the Informace entry. A notification still opens `/informace`.

`Info\InfoScreen` renders the screen for both addresses and for the send's refused form. With `inbox`, `templates/info.twig` wraps the screen in `<div class="infoview" data-info-root data-view="info|inbox" data-morph-keep="data-view">`: the pane `<div class="info-pane" id="info-view-info" role="tabpanel" aria-labelledby="info-tab-info">` with Informace's own content, the pane `#info-view-inbox` (`aria-labelledby="info-tab-inbox"`) with `templates/_inbox_pane.twig`, then Místa's floating pill (`.tabs-wrap`/`.tabs`/`.tabs-indicator`/`.tabs-tab`, `aria-label="Zobrazení informací"`): `Informace` (`fas fa-info-circle`, `id="info-tab-info"`, `href` the `info` route, `data-info-view="info"`) and `Zprávy` (`fas fa-envelope`, `id="info-tab-inbox"`, `href` the `inbox` route, `data-info-view="inbox"`). The server paints the view the address names (`InfoPage::$view` is the root's `data-view`); its pill carries `aria-selected="true"` and `aria-current="page"`, the other `aria-selected="false"`, both keep `data-morph-keep="aria-selected aria-current"`, and neither has a `tabindex`. Both panes are always in the markup; a hidden pane is `display: none`. Without JavaScript a pill is a full page load of its address; with it, `www/js/inbox/views.js` switches in place (below). The toast a write left sits above the root. `www/css/inbox.css` gives the screen the Program screen's column (`.screen[data-tab="programs"]`: no top padding, the bottom chrome's height below the column, `.infoview` at least `--screen-height` tall, each pane with the shared 8 px top and 16 px bottom), so the pill docks on the tab bar and its in-flow position at the foot of a long pane coincides with the docked one; `.infoview[data-view="inbox"]` slides the indicator to the second pill. An event without `inbox` has no root, no panes and no pills, and Informace renders as it always did.

**With JavaScript.** `www/js/inbox/index.js` wires each `[data-info-root]` once (marked `data-info-ready="1"`): the document's own screen when the module evaluates, and a screen the loader inserted on its `screen:shown` (Informace is one of the loader's screens, so the warm-up may have inserted it hidden; it is wired when it is first shown). `www/js/inbox/views.js` (`setupViews(root)`) makes the view the address, as `map/views.js` does for Místa: `infoViewFromPath(pathname, screen)` reads `info` from the screen's own path (`data-screen`) and `inbox` from that path plus `/zpravy`, null for any other address, and `infoViewPath(view, screen)` is its inverse. A plain primary click on a pill is `preventDefault()`ed: the pane is shown and `{screen, infoView}` pushed with `infoViewPath(view) + location.search`, unless the path is already that view's, so each switch is one Back and no document is loaded (a half-typed message stays in the hidden pane). A click with a modifier key (a new tab) is the browser's. Space presses a pill, once for a held key, and the arrow keys walk the pills (`ui/tabs.js`). A `popstate` while the screen is visible shows the view of the new address and pushes nothing. The wiring, every `screen:shown` and every `screen:morphed` read the view from `location.pathname`, never from the markup's `data-view`, because the worker stores one copy for both addresses; on an address that is not the screen's (a morph while the screen is hidden) the view on show stays. With the loader's `lastAddress`, a tab tap comes back to the view the screen was left on. `setView()` writes each pill's `aria-selected`, the roving `tabindex` and `aria-current="page"` on the shown one. Both views work offline, so unlike Místa there is no offline special case. `infoViewFromPath()`, `infoViewPath()`, `setupViews()` and the draft's `setupDraft()` are tested in `tests/js/inbox-views.test.js`.

## The form

The Zprávy pane is per reader, as Profil is. `Inbox\FormPage::pane()` builds its `Inbox\View\InboxPage` (who the reader writes as, the promise, `EventConfig::emergency()` for the call buttons, the values and the errors), which the template reads as `inbox`. The identity is read from the session after the screen's participant read, so a reader that read logged out gets the login card.

When the reader is logged out, the pane shows the heading `Napiš organizátorům`, the line `Napsat můžeš po přihlášení.` and Domů's login card (`_pair_card.twig`, as Program's Moje does), whose `Přihlásit se` leads to Profil, and no form. When the reader is paired, it shows, top to bottom:

1. the heading `Napiš organizátorům`;
2. when the event has crisis contacts, a `section.emergency.inbox-urgent` named by its first line, `Když jde o zdraví nebo bezpečí, nepiš a zavolej:` (plain text, not a link), and the same list of contacts the Informace view shows (`templates/_emergency_contacts.twig`), each with its `Zavolat` button, a `tel:` link with the spaces stripped; the screen is precached and a `tel:` link needs no network, so it dials offline too. An event without crisis contacts (obrok19) shows neither the line nor the list, so nothing points at a contact that does not exist;
3. `Píšeš jako <nickname>.`, or `Píšeš jako účastník č. <id>.` without a nickname;
4. the fieldset `O co jde?`, with two radio buttons and none preselected: `Potřebuju poradit nebo něco nefunguje` (`help`), with the event's promise line under it when the event has one (the radio's `aria-describedby`), and `Mám nápad nebo zpětnou vazbu` (`idea`). The whole label is the target, at least 44 px tall;
5. the textarea `Zpráva`, `required`, `maxlength="1000"`;
6. the text input `Telefon nebo e-mail, když chceš odpověď`, marked `nepovinné`, `maxlength="100"`;
7. `Zprávu a kontakt uvidí organizátoři akce.`;
8. the submit button `Odeslat`.

The form (`form[data-inbox-form]`, posting to `/<slug>/informace/zpravy`) is a plain `POST` with `data-write`, so it works without JavaScript, and `shell/write.js` sends it when there is a script.

## The send

`POST /<slug>/informace/zpravy` (`Inbox\SendAction`, route `inbox-send`) runs these checks in this order. The route has no `screen` argument: its 403 refusal pages are pages of their own (`data-screen` the request's path), which the loader must not file as Informace, and only the 422 and 429 re-renders set the Twig global `screenPath` to `/<slug>/informace` themselves:

1. `SameOrigin::allows()`. A cross-site form gets the 403 refusal page: `Zprávu se nepodařilo odeslat. Načti stránku a zkus to znovu.`, with `Zpět na formulář`.
2. The identity, read from the session only. The checks never call kissj (re-rendering a refused form below reads the participant, as every render of Informace does, for the programme notifications). A reader who is not paired gets the 403 refusal page: `Zprávu můžeš poslat až po přihlášení.`, with `Přihlásit se` to Profil.
3. Validation (`Inbox\Submission`). A failure renders the whole Informace screen again (`Info\InfoScreen`), painted on the Zprávy view, with status 422, the typed values and the errors, in this order:
   - no kind, or an unknown one: `Vyber, o co jde.`;
   - an empty text after trimming: `Napiš zprávu.`;
   - a text over 1000 characters: `Zpráva smí mít nejvýš 1000 znaků.`;
   - a contact over 100 characters: `Kontakt smí mít nejvýš 100 znaků.`.
   `Submission::fromForm()` trims both fields and normalises line endings to `\n` first; lengths are counted in characters (`mb_strlen`).
4. The repeat (`Inbox\InboxWriter`). When the same participant already sent a message of the same kind with the same text in this event in the last minute, nothing is inserted and the send answers as a success. This makes a double tap, and the second POST that `write.js` sends after a 5xx, safe. It runs before the throttle, so a double tap on the fifth message of the hour is still a success.
5. The throttle. A participant can send at most 5 messages per event in any 60 minutes (`InboxWriter::PER_HOUR`), counted from their own rows in `inbox_messages`. Over the limit, the screen renders again on the Zprávy view with status 429 and `Za hodinu můžeš poslat nejvýš 5 zpráv. Zkus to později.`. The repeat check, the count and the insert run in one SQLite `BEGIN IMMEDIATE` transaction (`InboxRepository::transact()`, rolled back on a throw), so two sends that arrive at once cannot both pass the checks and store the message twice.

On success (a saved message or a repeat), the send leaves the toast `Zpráva odeslána, díky!` (`Toast\Toasts::put()`). For the `help` kind of an event with a promise, the promise line is the toast's second line; for `idea`, or for an event without a promise, there is no second line. Then it answers through `Http\WriteResponse::redirect()` with `/<slug>/informace/zpravy#odeslano`, the Zprávy view: a 302 for a plain form, `{"next": …}` for `write.js`. Informace takes the waiting toast on a full page (not a fragment, not the worker's fill) and shows it once, on either view ([info.md](info.md)).

The text is stored as typed and escaped on output. The contact is shown as plain text, never as a link.

## Offline and the draft

The Zprávy view needs no document of its own in the precache list: its address is an alias of the Informace entry ([service-worker.md](service-worker.md)), so it opens from the worker's copy of Informace, whose markup holds both panes. The send is a same-scope POST, so the worker purges the stored pages after the server answered it, as for every write.

A send that cannot reach the server is answered by the worker as every write is: back to the Zprávy view with `#offline`, which shows the shell's offline toast (`Akci není možné provést, protože jsi offline.` / `Zkus to znovu až najdeš místo se signálem.`). There is no queue.

`www/js/inbox/index.js` keeps a draft, so the typed message survives an offline send or a server error. It stores the kind, the text, the contact and the draft's owner (the participant id of the page's `push-identity` meta) in `localStorage` under `'inboxDraft:' + <the event-base meta's content>`, which ends in a slash (`inboxDraft:/obrok19/`). It saves the draft on every `input` and `change` event of the form, and removes the key when all three fields are empty. When the screen with the form is wired (`setupDraft(root, storage, key, identity)`, the document's own screen or one the loader inserted, on its first show) and the server rendered all three fields empty, the script fills them from the draft; otherwise it adopts what the server rendered (each value set again from the script), so every field is dirty either way and a background morph to the server's empty form (a revalidation after a push, with no field focused) no longer reaches the values: the echo of a refused send stays in the form as typed text does. The `input` and `change` listeners sit on the root, which the morph never replaces. So the server's echo after a 422 or a 429 wins over the draft. The draft is removed when the server confirmed the send: when the screen is wired or shown on an address with `#odeslano` (the Zprávy view, after the redirect), the script removes the draft and strips the hash with `history.replaceState(history.state, '', pathname + search)`. It is also removed on any page whose `push-identity` is not the draft's owner, a logged-out page included, and it is restored only for its owner: a logout leaves `localStorage` as it is, and the next reader of the browser must not get the last one's text and contact. This owner rule runs on every page when the module evaluates (every module's script loads on every page, and a logout lands on Profil). A login and a logout are full navigations, so the script sees every change of identity. A draft without an owner counts as broken and is removed. The send's landing is a full page load of the Zprávy address (the redirect, or `write.js`'s `location.replace()`), whose screen is the document's own and is wired at evaluation. Because the Zprávy pane stays in the document while the Informace view is on show, a switch to Informace and back keeps the typed text in the form itself. Its pure parts (`draftKey()`, `readDraft()`, `writeDraft()`, `keepDraft()`, `shouldRestore()`) are tested in `tests/js/inbox.test.js`.

Without JavaScript there is no worker and no draft. The browser shows its own error, and Back returns to the filled form.

## Storage

`Storage\Migrator` step 2 creates the table ([push.md](push.md) has the migrator):

```sql
CREATE TABLE inbox_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event TEXT NOT NULL,
    sent_at TEXT NOT NULL,          -- the clock's local time, Y-m-d H:i:s, as messages.sent_at
    participant_id INTEGER NOT NULL,
    nickname TEXT NULL,             -- as kissj gave it at send time
    kind TEXT NOT NULL,             -- 'help' or 'idea'
    body TEXT NOT NULL,
    contact TEXT NULL,
    handled INTEGER NOT NULL DEFAULT 0,
    handled_at TEXT NULL,
    handled_by TEXT NULL
);
CREATE INDEX inbox_messages_list ON inbox_messages (event, handled, sent_at);
CREATE INDEX inbox_messages_sender ON inbox_messages (event, participant_id, sent_at);
```

`Inbox\InboxRepository` takes the container's `PDO` and runs no DDL. It hands out `Inbox\InboxMessage` objects read through `Storage\Row`; `sentAt` and `handledAt` stay the stored strings. An empty nickname and an empty contact are stored as null. Nothing is deleted automatically: an organiser deletes a message by hand.

The migrator refuses a database at a version above the code's. After step 2 has run in production, rolling the code back to a release before this one needs the database file handled by hand.

## The admin section

The inbox is a section of the one admin page, `/<slug>/admin/notify`, for an event with `inbox` only (`Admin\AdminPageRenderer`, `Admin\View\InboxSection`, `templates/_admin_inbox.twig`). The shared link, the login (`AdminSession`), the `csrf` field and `AdminHeadersMiddleware` are the admin page's own ([push.md](push.md)).

The page's order is the send form, then the section `Zprávy od účastníků` (`id="inbox"`), then the notification log. A link at the top of the page jumps to the section and carries the count of unhandled messages, with the Czech plural: `Zprávy od účastníků (1 nevyřízená)`, `(2 nevyřízené)` to `(4 nevyřízené)`, and `(0 nevyřízených)`, `(5 nevyřízených)` and up (`InboxSection::unhandledLabel()`).

The section contains:

- `Zprávy obsahují osobní údaje a samy se nemažou. Nepotřebné smazat.`;
- every unhandled message, in two groups with their own headings, newest first in each: `Potřebují poradit nebo něco nefunguje`, then `Nápady a zpětná vazba`. A group without messages has no heading. An inbox with no unhandled message says `Žádné nevyřízené zprávy.`;
- the handled messages in a `<details>` (`Vyřízené (N)`), newest first, 20 to a page (`AdminPageRenderer::INBOX_PAGE`), with `Novější` and `Starší` links. The page is the query parameter `inbox=<page>`, which is independent of the log's `page`. The `<details>` is open whenever `inbox` is present in the query. Both actions below redirect with `inbox=<n>`, so the list is open after every action. A page past the last one shows the last page there is (page 1 when there is none), so deleting or returning the last message of the last page does not land on an empty list. The log's own paging links do not carry `inbox`, so they close it.

Each message shows when it was sent (`j. n. Y H:i`, the stored local time, which is Prague time), the sender (`<nickname> · č. <id>`, or `Účastník · č. <id>`), the text with its line breaks, `Kontakt: …` when there is one, and for a handled message `Vyřízeno: <Podpis>, <when>`.

Two actions per message, each a POST with the admin `csrf` field and the message's `page` and `inbox`:

- `POST /admin/inbox/{id}/handled` (`Inbox\HandledAction`, route `admin-inbox-handled`): `Vyřízeno` marks the message handled and records the `Podpis` and the time; on a handled message, `Vrátit mezi nevyřízené` returns it to the unhandled ones and clears both. Marking a message that is already handled keeps the first mark, so two organisers who handle it at once do not overwrite each other.
- `POST /admin/inbox/{id}/delete` (`Inbox\DeleteAction`, route `admin-inbox-delete`): deletes the row for good. In the page it sits in a `<details>` (`.inbox-delete`): the summary `Smazat…` shows the `Podpis` field and the submit button `Opravdu smazat`, so one tap deletes nothing and no script or browser dialog is needed. The app log gets one line, `inbox.deleted`, with the event, the message id and the `Podpis`.

Both actions need the `Podpis` under the hide toggle's rule (required, at most 40 characters; otherwise the page renders again with status 422 and `Podpis je povinný a smí mít nejvýš 40 znaků.`). A closed session or a stale `csrf` gets the admin page's 403 page. An unknown id, or an id of another event, is a bare 404. On success both answer `303` to `/admin/notify?page=<page>&inbox=<n>#inbox`. Both routes carry `AdminHeadersMiddleware`, and the worker never touches `/admin/`. The `Vyřízeno` button and the `Smazat…` summary are at least 44 × 44 (`www/css/admin.css`).

The page's inline script fills every empty `signature` field with the `Podpis` this browser stored (`localStorage['notifySignature']`). It stores the `Podpis`, trimmed and when not empty, on the submit of every form with a `signature` field: the send form, the hide toggles and both inbox actions. Only the send form (`#notify-form`) also disables its button on submit.

## What is never logged

No message text and no contact goes into a log line, an exception message or Sentry. The one log line of the feature is the delete's `inbox.deleted` (event, id, `Podpis`). Sentry's SDK attaches the parsed form body to every error event, so `Telemetry\Scrubber` replaces the whole body of `POST /informace/zpravy` (text and contact; any path that ends in `/zpravy`) and of every admin route (`csrf` and `Podpis`) before an event leaves ([telemetry.md](telemetry.md)).
