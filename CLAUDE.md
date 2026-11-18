# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Modular support web app for Czech scout events (Obrok). Built on Slim 4 (PHP >= 8.5). One codebase serves multiple events — each event is a config + content directory under `events/<slug>/` plus public assets under `www/events/<slug>/`, selected at runtime by the first path segment: every event lives under `/{slug}/`, and `/` is a picker of the listed ones. Code is in English — comments, docblocks, exception messages, identifiers. Czech is reserved for what users actually read: UI strings in `templates/`, per-event content under `events/<slug>/content/`, user-facing notices, toasts and flash messages, and the weekday names in `HomeModel`, `NotificationList`, `Programs\Times` and `www/js/home/countdown.js`. Every surface a reader sees addresses them as *ty*: templates, notices and toasts, push status lines, ARIA labels, `<title>`s. UI strings spell the registration system **KissJ**, and the organisers' messages are **notifikace**, never novinky (the Informace heading, Domů's `Starší notifikace`, the manifest shortcut). The admin page is for organisers and stays neutral and imperative, and per-event content is the organisers' own text. Commit messages and `docs/` are in English without AI-authorship trailers.

## Commands

- `docker-compose up` — run the dev server (PHP built-in server inside `docker/dev.Dockerfile`, `php:8.5-alpine` plus Composer, `gmp` and `zip`, so `docker exec -u 1000 -it eventapp-slim-1 sh` has `composer` ready; Alpine has no bash; with `bin/router.php` and `PHP_CLI_SERVER_WORKERS=4`: one worker queued a page behind the service worker's install burst and the loader's warm-up long enough for the worker's 3 s cached-copy fallback), then visit `localhost:8081`
- `composer start` — alternative: run locally via `php -S localhost:8081 -t www/ bin/router.php`. The router is not optional: without it the built-in server 404s any missing path with a dot in it (`/<slug>/precache.json`) before PHP runs
- `composer test` / `vendor/bin/phpunit` — run tests (PHPUnit 13, config in `phpunit.xml`, suites `unit`, `functional`, `browser`). Without a Chrome every `browser`-group test skips itself ("OK, but some tests were skipped!"); CI sets `PANTHER_NO_SKIP=1`, where that is a failure
- `vendor/bin/phpunit --exclude-group browser` — the fast run, no Chrome tests. There is no host PHP: run it as `docker run --rm -v "$PWD":/app -w /app php:8.5-alpine vendor/bin/phpunit --exclude-group browser` — 8.5 is the lowest supported PHP and what CI and production run (`.github/php-version`, read by every workflow; `deploy.yml` deploys every push to `master` over FTP once `ci.yml` passes, see `docs/deployment.md`). The fast run keeps the local source's state in temp directories of its own; a `var/stub` it leaves behind is a bug
- `docker compose --profile browser run --rm --use-aliases test vendor/bin/phpunit --group browser` — the Chrome tests: `symfony/panther` drives `selenium/standalone-chrome` (the `chrome` service), and the test case serves the app itself from the `test` container (`--use-aliases` makes it reachable as `test`). `docker compose --profile browser stop chrome` afterwards, and `docker run --rm -v "$PWD":/app -w /app php:8.5-alpine sh -c 'rm -rf var/twig/* var/sessions var/cache var/stub'`, because the `test` service runs as root. There is no Node anywhere
- `vendor/bin/phpunit --filter testGetHomepage` — run a single test
- `docker run -d --name eventapp-korbo --rm -p 8082:8080 -v "$PWD":/app -w /app -e PROGRAM_PROVIDER_KORBO26=local -e APP_DEBUG=1 php:8.5-alpine php -S 0.0.0.0:8080 -t www/ bin/router.php` — serve the `korbo26` dev event (the realistic Korbo camp data from its local fixture files) on `localhost:8082/korbo26/programy`; the `-e` values override `.env`. How it pairs and registers: `docs/architecture/bootstrap-and-config.md`
- `docker run --rm -v "$PWD":/app -w /app php:8.5-alpine sh -c 'apk add --no-cache libpng-dev >/dev/null && docker-php-ext-install gd >/dev/null && php bin/event-icons.php <slug> <source.png> <#background>'` — regenerate an event's favicons, apple-touch icon and manifest icons from one drawing (GD is installed on the fly, the stock image lacks it); it prints the `assets.favicon16`/`favicon32`/`appleTouch` lines for the config, and files it wrote as root need a `chown` afterwards
- `docker run --rm -v "$PWD":/app -w /app php:8.5-alpine php bin/kissj-fixtures.php <slug> [--clamp-all-day=HH:MM-HH:MM]` — regenerate `events/<slug>/fixtures/` from `tests/fixtures/kissj/<slug>/` (`programme-list.json`, `participant.json`) through the real `KissjProgramProvider` mapping: `programs.json`, `sections.json`, `registered.json` (the fixture participant's programmes, keyed by their kissj participant id) and `registration.json` (the list's window, removed when it has none); `--clamp-all-day` narrows programmes that kissj stores as whole-day runs to the given window
- `docker compose --profile browser run --rm --use-aliases test php bin/baseline.php` — re-take `docs/baseline/obrok19/*.png` (the four obrok19 screens `home`, `informace`, `profil`, `programy` at 390 × 844 through the local provider, in the browser tests' own server and Chrome, the install button hidden). obrok19 has no `dates`, so Domů shows the date heading of the day it is shot: two runs on the same day give byte-identical PNGs, two on different days differ there. `docker compose --profile browser stop chrome` afterwards; with a local chromedriver, `php bin/baseline.php`
- `docker run --rm -v "$PWD":/app -w /app php:8.5-alpine vendor/bin/php-cs-fixer fix --dry-run --diff` — check the PHP code style (PER-CS 2.0, `.php-cs-fixer.dist.php`); drop `--dry-run --diff` to fix it
- `docker run --rm -v "$PWD":/app -w /app php:8.5-alpine vendor/bin/phpstan analyse --memory-limit=1G` — PHPStan at level max over `src` (`phpstan.neon.dist`) with zero errors and no baseline: any error fails
- `docker run --rm -v "$PWD":/code -w /code ghcr.io/biomejs/biome:2.6.8 ci` — Biome (`biome.jsonc`): lint over `www/`'s scripts and stylesheets, formatting where it is enabled
- `just lint` — the three above (`just cs`, `just stan`, `just biome`); CI's `lint` job runs the same three
- `docker run --rm -v "$PWD":/app -w /app php:8.5-alpine sh -c 'apk add --no-cache libfaketime tzdata >/dev/null && php bin/gate.php html <label>'` — the HTML gate: dump every event's HTTP responses under a frozen clock into `var/gate/<label>/html/`
- `docker compose --profile browser run --rm --use-aliases test sh -c 'apk add --no-cache libfaketime tzdata >/dev/null && php bin/gate.php render <label>'` — the render gate: dump every element's box and computed style per screen state in Chrome into `var/gate/<label>/render/`; `docker compose --profile browser stop chrome` afterwards
- `docker compose --profile browser run --rm --use-aliases test vendor/bin/phpunit --filter JsUnitTest` — the JavaScript unit tests (`tests/js/`) in Chrome; clean up as after the Chrome tests
- `php bin/gate.php diff [--frontend] <a> <b>` — compare two gate labels after normalisation: `identical: N files`, or each differing file with a short diff; `--frontend` also drops script and stylesheet elements
- Local only, not in the repo (`.gitignore`): the mock events' source material under `docs/` and the two converters that read it, with their tests. Only the JSON they produced is committed, so the two commands below work only on a machine that still has those files.
- `docker run --rm -v "$PWD":/app -w /app php:8.5-alpine php bin/obrok-harmonogram.php "docs/Harmonogram celé akce.xlsx"` — convert the organisers' harmonogram spreadsheet into `tests/fixtures/kissj/obrok27/`; then run `docker run --rm -v "$PWD":/app -w /app php:8.5-alpine php bin/kissj-fixtures.php obrok27` to regenerate the served `events/obrok27/fixtures/` from it
- `docker run --rm -v "$PWD":/app -w /app php:8.5-alpine php bin/miquik-rozvrh.php "docs/MQ-Přednášky - data.xlsx"` — convert Miquik's lecture workbook (the `Rozvrh` room grid joined to the speakers' form answers) into `tests/fixtures/kissj/miquik26/`; then `bin/kissj-fixtures.php miquik26`. The workbook has no date, so every talk sits on the invented `MiquikRozvrh::DATE`

Before running, copy `.env.example` to `.env`, set `APP_DEBUG=1` for dev, keep a `PROGRAM_PROVIDER_<SLUG>` line for every event (`.env.example` sets all five to `local`) and fill in a VAPID pair — `docker run --rm -v "$PWD":/app -w /app php:8.5-alpine php bin/generate-vapid.php` prints the two lines; every event has push, and a push event refuses to boot without valid keys (tests generate their own per run in `tests/bootstrap.php`). There is no event to choose, the URL does (`localhost:8081/obrok19/`, the reference event).

## Modules

One row per screen, as the code stands on 2026-11-18. A screen is one name in three places: a PHP module under `src/Module/<Name>/` (the module class registers its routes, relative to `/<slug>`, each route an action class in the same directory), a script directory `www/js/<name>/` whose `index.js` is the module's `script()`, and a sheet `www/css/<name>.css`, its `stylesheet()`. Three modules serve no screen: `Shell\ShellModule` (`/offline`, `/precache.json`; `www/js/shell/` and `www/css/shell.css` on every page), `Push\PushModule` (`POST /push/subscribe`, `www/js/push/`) and `Handbook\HandbookModule` (the handbook PDF). The routes are in each screen's document.

| Screen | PHP | JS | CSS | Template |
|---|---|---|---|---|
| Domů | `src/Module/Home/` | `www/js/home/` | `www/css/home.css` | `homepage.twig` |
| Program | `src/Module/Programs/` | `www/js/programs/` | `www/css/programs.css` | `programs.twig` |
| Místa | `src/Module/Map/` | `www/js/map/` | `www/css/map.css` | `map.twig` |
| Informace | `src/Module/Info/` | none (`www/js/ui/reltime.js` redraws the times) | `www/css/info.css` | `info.twig` |
| Profil | `src/Module/Profile/` | `www/js/profile/`, `www/js/push/` (opt-in) | `www/css/profile.css` | `profile.twig` |
| Admin page (with `push`) | `src/Module/Admin/` | inline `<script>` | `www/css/admin.css` | `admin-notify.twig`, `admin-expired.twig` |

**Adding a screen**: one PHP module, one JS directory, one CSS file, under one name. `src/Module/<Name>/<Name>Module.php` implements `ModuleInterface`, is listed in `ModuleRegistry::ORDER` and in `CORE` (every event) or `MODULES` (a feature), and returns `'<name>/index.js'` from `script()` and `'<name>.css'` from `stylesheet()`. Its place in `ORDER` is its sheet's place in the link order ([theming.md](docs/architecture/theming.md)).

## Conventions

- English for code, identifiers, comments, docblocks and exception messages. Czech appears only where a reader looks: templates, the content files of each event, notices, toasts, and the four places that print weekday names.
- Readers are addressed as *ty* on every surface; the organisers' admin page is neutral and imperative.
- In UI text the registration system is written **KissJ**.
- The organisers' messages are called **notifikace** in the UI. Do not write novinky.
- No Node, ever; every tool runs in a Docker container.
- There is no PHP on the host: run every test and script in Docker on `php:8.5-alpine`.
- Commit messages and files under `docs/` are written in English and carry no trailer that credits an AI.
- Agents do not commit, stage, branch or push. Committing is for the user.
- When behaviour changes, update the area document under `docs/architecture/` that describes it, not this file.

## Where things are

- `docs/architecture/bootstrap-and-config.md`: the shipped events, boot, env variables, `Kernel::create()`, `EventConfig`, modules and tab order.
- `docs/architecture/http.md`: the middleware order, the CSP and its nonce, the error pages.
- `docs/architecture/telemetry.md`: Sentry, spans and tags, scrubbing, the loggers, `/health`.
- `docs/architecture/program-provider.md`: the provider interface, the stub and kissj sources, sections, the kissj cache.
- `docs/architecture/auth-and-session.md`: device pairing, logout, the session store and its cookie.
- `docs/architecture/shell-and-loader.md`: the layout, the screen stack, the loader and its morph, toasts, the `www/js/` modules, asset versions, the install card.
- `docs/architecture/service-worker.md`: the precache list, cache strategies, purge on write, offline answers.
- `docs/architecture/theming.md`: the core stylesheets, palette, semantic roles, light and dark sets, theme and fonts, logos per surface.
- `docs/architecture/home.md`: Domů in its three phases.
- `docs/architecture/programs.md`: the Program screen, its three views, day chips, the sheet and registration.
- `docs/architecture/map.md`: Místa, the Google map and the site plan.
- `docs/architecture/info.md`: Informace, notifications for readers, quick links, the crisis contact.
- `docs/architecture/push.md`: the admin page, the message log, storage and migrations, subscriptions, sending, the opt-in.
- `docs/architecture/tests.md`: the test classes of each suite and the base classes behind them.

## Rules that are easy to break

- No colour literal and no `--color-*` key in `www/css/`: rules read semantic roles. See `docs/architecture/theming.md`.
- A colour change touches both hand-written role sets, light and dark. See `docs/architecture/theming.md`.
- A stylesheet carries `?v=<hash>` (`asset_version()`), a script lives under `/js/<hash>/` (`script_url()`); a changed font or icon file ships under a new name. See `docs/architecture/shell-and-loader.md`.
- Never give `<main>` a `view-transition-name` or anything else that makes it a stacking context. See `docs/architecture/shell-and-loader.md`.
- Insert a screen, show it, and only then initialise it, since a hidden subtree measures as zero. See `docs/architecture/shell-and-loader.md`.
- Keep a `data-key` or id on every child the morph must match, and list client-owned attributes in `data-morph-keep`. See `docs/architecture/shell-and-loader.md`.
- Inline scripts need `nonce="{{ cspNonce }}"`, and no element may have an `on*=` attribute. See `docs/architecture/http.md`.
- A write is a plain form POST and a full navigation, which lets the worker purge stored pages. See `docs/architecture/service-worker.md`.
- No secret in an exception message, since Sentry sends messages unscrubbed. See `docs/architecture/telemetry.md`.
- Once deployed, a migration step is never edited; append a new one. See `docs/architecture/push.md`.
