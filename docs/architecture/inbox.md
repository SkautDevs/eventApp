# Inbox

This document covers `Napiš organizátorům`: the feature flag and its content file, the card on Informace, the form page and its send, the offline behaviour and the draft, the `inbox_messages` table, and the inbox section of the admin page.

## The feature and its content file

`inbox` is a feature of its own, served by `Inbox\InboxModule` (`src/Module/Inbox/`, `www/js/inbox/`, `www/css/inbox.css`). It is listed in `ModuleRegistry::MODULES` and in `ORDER` right after `InfoModule`, so its routes, its script and its sheet follow Informace's. The module has no tab (`menuItem()` is null): the way in is its card on Informace. It is opt-in per event, and off by default. obrok19 and obrok27 enable it.

`EventConfig::load()` refuses two configs ([bootstrap-and-config.md](bootstrap-and-config.md)):

- an event that enables `inbox` without `info`, because the card is on Informace. `info` requires `push`, so every event with `inbox` has the admin page too;
- an event that enables `inbox` without `content/inbox.php`, or with a `promise` that is empty after trimming. No team gets an inbox without saying how fast it reads it.

`events/<slug>/content/inbox.php` returns `['promise' => '<one sentence>']`, and `EventConfig::inboxPromise()` reads it, trimmed (`''` without the file). The promise is the line under the `help` kind on the form and the second line of the toast after a `help` message. obrok19's promise is `Zprávy čteme průběžně.`. obrok27 carries the same sentence as a placeholder until its organisers give their own, and the file says so.

The code name is `inbox` because the table name `messages` is the notification log's. What a participant sends is a **zpráva** in the UI, never a notifikace, which stays the organisers' word for their own messages.

## The card on Informace

For an event with `inbox`, `templates/info.twig` puts a `.link-card.info-inbox` (`data-key="inbox-card"`, so the morph matches it) directly under the crisis contact and above `Notifikace`, outside the quick links' list. It has no icon. Its title is `Napiš organizátorům` and its note is `Dotaz, problém nebo nápad. Když jde o zdraví nebo bezpečí, volej krizový kontakt.`. When the reader is paired, it links to the form page. When the reader is logged out, it links to Profil and carries a second note, `Napsat můžeš po přihlášení.`. It is an ordinary navigation, as the handbook link is: the form page is not one of the loader's screens. Informace itself is described in [info.md](info.md).

## The form page

`GET /<slug>/zpravy` (`Inbox\ShowAction`, route `inbox`, `templates/inbox.twig`, rendered through `Inbox\FormPage` from an `Inbox\View\InboxPage`) is a full document that lights the Informace tab. It also answers as a fragment (`X-Screen: 1`), which the worker's `storeBoth()` stores beside the page. The page is per reader, as Profil is.

When the reader is logged out, the page shows the heading and the login card (`_pair_card.twig`), and no form. When the reader is paired, it shows, top to bottom:

1. the heading `Napiš organizátorům`;
2. `Když jde o zdraví nebo bezpečí, nepiš a volej krizový kontakt.`, a link to Informace;
3. `Píšeš jako <nickname>.`, or `Píšeš jako účastník č. <id>.` without a nickname;
4. the fieldset `O co jde?`, with two radio buttons and none preselected: `Potřebuju poradit nebo něco nefunguje` (`help`), with the event's promise line under it (the radio's `aria-describedby`), and `Mám nápad nebo zpětnou vazbu` (`idea`). The whole label is the target, at least 44 px tall;
5. the textarea `Zpráva`, `required`, `maxlength="1000"`;
6. the text input `Telefon nebo e-mail, když chceš odpověď`, marked `nepovinné`, `maxlength="100"`;
7. `Zprávu a kontakt uvidí organizátoři akce.`;
8. the submit button `Odeslat`.

The form (`form[data-inbox-form]`) is a plain `POST` with `data-write`, so it works without JavaScript, and `shell/write.js` sends it when there is a script.

## The send

`POST /<slug>/zpravy` (`Inbox\SendAction`, route `inbox-send`) runs these checks in this order:

1. `SameOrigin::allows()`. A cross-site form gets the 403 refusal page: `Zprávu se nepodařilo odeslat. Načti stránku a zkus to znovu.`, with `Zpět na formulář`.
2. The identity, read from the session only. The send never calls kissj. A reader who is not paired gets the 403 refusal page: `Zprávu můžeš poslat až po přihlášení.`, with `Přihlásit se` to Profil.
3. Validation (`Inbox\Submission`). A failure renders the form again with status 422, the typed values and the errors, in this order:
   - no kind, or an unknown one: `Vyber, o co jde.`;
   - an empty text after trimming: `Napiš zprávu.`;
   - a text over 1000 characters: `Zpráva smí mít nejvýš 1000 znaků.`;
   - a contact over 100 characters: `Kontakt smí mít nejvýš 100 znaků.`.
   `Submission::fromForm()` trims both fields and normalises line endings to `\n` first; lengths are counted in characters (`mb_strlen`).
4. The repeat (`Inbox\InboxWriter`). When the same participant already sent a message of the same kind with the same text in this event in the last minute, nothing is inserted and the send answers as a success. This makes a double tap, and the second POST that `write.js` sends after a 5xx, safe. It runs before the throttle, so a double tap on the fifth message of the hour is still a success.
5. The throttle. A participant can send at most 5 messages per event in any 60 minutes (`InboxWriter::PER_HOUR`), counted from their own rows in `inbox_messages`. Over the limit, the form renders again with status 429 and `Za hodinu můžeš poslat nejvýš 5 zpráv. Zkus to později.`. The repeat check, the count and the insert run in one SQLite `BEGIN IMMEDIATE` transaction (`InboxRepository::transact()`, rolled back on a throw), so two sends that arrive at once cannot both pass the checks and store the message twice.

On success (a saved message or a repeat), the send leaves the toast `Zpráva odeslána, díky!` (`Toast\Toasts::put()`). For the `help` kind, the event's promise line is the toast's second line; for `idea` there is no second line. Then it answers through `Http\WriteResponse::redirect()` with `/<slug>/informace#odeslano`: a 302 for a plain form, `{"next": …}` for `write.js`. Informace takes the waiting toast on a full page and shows it ([info.md](info.md)).

The text is stored as typed and escaped on output. The contact is shown as plain text, never as a link.

## Offline and the draft

The form page is in the precache list of an event with `inbox`, after the tabs and before `/offline` ([service-worker.md](service-worker.md)), so it opens from the worker's copy. The send is a same-scope POST, so the worker purges the stored pages after the server answered it, as for every write.

A send that cannot reach the server is answered by the worker as every write is: back to the form page with `#offline`, which shows the shell's offline toast (`Akci není možné provést, protože jsi offline.` / `Zkus to znovu až najdeš místo se signálem.`). There is no queue.

`www/js/inbox/index.js` keeps a draft, so the typed message survives an offline send or a server error. It stores the kind, the text, the contact and the draft's owner (the participant id of the page's `push-identity` meta) in `localStorage` under `'inboxDraft:' + <the event-base meta's content>`, which ends in a slash (`inboxDraft:/obrok19/`). It saves the draft on every `input` and `change` event of the form, and removes the key when all three fields are empty. When the form page loads and the server rendered all three fields empty, the script fills them from the draft. So the server's echo after a 422 or a 429 wins over the draft. The draft is removed when the server confirmed the send: when a page loads with `#odeslano` (Informace, after the redirect), the script removes the draft and strips the hash with `history.replaceState()`. It is also removed on any page whose `push-identity` is not the draft's owner, a logged-out page included, and it is restored only for its owner: a logout leaves `localStorage` as it is, and the next reader of the browser must not get the last one's text and contact. A login and a logout are full navigations, so the script sees every change of identity. A draft without an owner counts as broken and is removed. The form page is not a tab, so it is always a full navigation, and the script starts on `DOMContentLoaded`. Every module's script loads on every page, so the script also sees the hash on Informace. Its pure parts (`draftKey()`, `readDraft()`, `writeDraft()`, `keepDraft()`, `shouldRestore()`) are tested in `tests/js/inbox.test.js`.

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

No message text and no contact goes into a log line, an exception message or Sentry. The one log line of the feature is the delete's `inbox.deleted` (event, id, `Podpis`). Sentry's SDK attaches the parsed form body to every error event, so `Telemetry\Scrubber` replaces the whole body of `POST /zpravy` (text and contact) and of every admin route (`csrf` and `Podpis`) before an event leaves ([telemetry.md](telemetry.md)).
