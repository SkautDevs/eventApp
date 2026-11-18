# Bootstrap and config

This document covers the shipped events, how a request becomes an event's app in `src/Kernel.php`, the event config and its content files, and how modules join the tab bar.

## The events

Five events ship: `obrok19` (the reference), `obrok27` (19.-23. 5. 2027, the official brand kit), `korbo26` (the former `korbo`), `navigamus27` (3.-6. 6. 2027; its programmes are still the 2025 side programme, moved 104 weeks on; its look is the 2027 identity from the organisers' manual, mirrored from kissj's Navigamus 2027 skin: deep purple, Seatren and Luciao Sans, a wavy purple app bar and wavy cards in `www/events/navigamus27/style.css`) and `miquik26` (unlisted: its lecture data is in, its date is invented). obrok19 and obrok27 enable `map`, `programs`, `handbook`, `info` and `push`; navigamus27 enables `programs`, `map`, `push` and `info`; korbo26 and miquik26 enable `programs`, `push` and `info`. Two of them are **unlisted**, i.e. absent from the picker but reachable by URL: `miquik26` and `obrok19`, neither of which has a `listed` key at all, so both default to unlisted (obrok19 is served at `/obrok19/`). **korbo26, navigamus27 and miquik26 wear kissj's design**, adjusted to eventApp's contrast rules. A slug matches `/^[a-z0-9-]+$/` and ends in a two-digit year. Every event's fixtures know one participant, kissj's participant id `1` (`registered.json`: korbo26 6 programmes, obrok19 and obrok27 8, miquik26 5, navigamus27 2); only korbo26 names them in `participants.json` (`Jana Nováková`), so elsewhere the paired reader is `Účastník`. Registration windows (`registration.json`): korbo26 open 2020–2099, obrok27 from 15. 3. 2027 18:00 to the event's start; the others have none. An event's `config.php` may also carry `listed` and `dates` (`start`/`end`), which the picker uses to split upcoming from past and Domů uses for its phases; the three listed events carry both, while `miquik26` and `obrok19` carry neither. The optional `website` (an absolute http(s) URL, validated at load; obrok27: `https://obrok.skaut.cz/`) is not shown anywhere at the moment.

## Front controller and boot

`www/index.php` is a 3-line front controller: it requires the Composer autoloader and calls `App\Kernel::boot($_SERVER['REQUEST_URI'] ?? '/')->run()`, which picks the event from the first path segment (see `Kernel::boot()` below). All bootstrap logic lives in `src/Kernel.php` — no `settings.php`/`dependencies.php`/`middleware.php`/`routes.php` split, no route closures scattered across a routes file.

`Kernel::boot($_SERVER['REQUEST_URI'])` loads `.env` (if present) and looks at the first path segment: if `EventCatalog` knows it, `Kernel::create()` builds the app for that event; otherwise `Kernel::createInstance()` builds the instance-level app that serves the picker at `/` and Slim's 404 for the rest. Nothing is configured per deployment about which event is served. Env vars are per event where they are secrets or choices of an event — `<NAME>_<SLUG>` with the slug upper-cased and `-` turned into `_` (`PROGRAM_PROVIDER_OBROK27`, `KISSJ_API_KEY_OBROK27`, `ADMIN_TOKEN_OBROK27`), read through `EventConfig::env()` (which falls back from `$_ENV` to `getenv`, because php-fpm may leave `$_ENV` empty); `APP_DEBUG`, `PROGRAM_CACHE_TTL`, `VAPID_*`, `PUSH_DB_PATH`, `SESSION_PATH` (`Kernel::sessionPath()`, default `var/sessions`, relative paths against the project root like `PUSH_DB_PATH`; the browser tests point it at a temp dir), `STUB_STATE_PATH` (`Kernel::stubStatePath()`, default `var/stub`, resolved the same way; every test points it at a temp dir), `PUSH_ENDPOINT_HOSTS`, `TRUSTED_PROXY_COUNT`, `TRUSTED_PROXIES`, `SENTRY_DSN`, `SENTRY_TRACES_SAMPLE_RATE` (`.env.example`: 0.05), `SENTRY_PROFILES_SAMPLE_RATE` and `APP_RELEASE` are instance-wide and read from `$_ENV` (`TRUSTED_PROXIES` falls back to `getenv`). There is no instance-wide kissj URL.

## The reader's address, logging and time zone

The reader's address is `Http\ClientIp::of()`: `X-Forwarded-For` is read, `TRUSTED_PROXY_COUNT` hops from its right end, only when the connection comes from a loopback or private address (an IPv4-mapped one judged as its IPv4) or from one `TRUSTED_PROXIES` names (comma-separated addresses or CIDRs, a malformed entry skipped; only the immediate peer is matched, the inner hops are trusted by the count); otherwise it is `REMOTE_ADDR`. Both limits — the pairing start's and the push subscribe one — count by `ClientIp::throttleKey()`, which is that address for IPv4 and its /64 for IPv6, with `::ffff:` unwrapped. A 404 or 405 is not logged (`src/QuietErrorHandler.php`), and `Kernel::TIMEZONE` is `Europe/Prague`.

## Building an event's app

`Kernel::create()`:
- Calls `Kernel::programSource()` first (see the provider below), then builds a PHP-DI container (`ContainerBuilder`) with the event, the program provider, `Session`, `Auth\Authenticator`, `Auth\PairingThrottle` and Twig as definitions.
- Creates the Slim 4 `App`, adds body-parsing/routing middleware, Twig middleware, and error middleware (`APP_DEBUG` controls whether error details are shown), and then the session-cookie and screen middleware, *outside* the error middleware rather than inside it; the security headers, `Http\CspMiddleware` and `Telemetry\TransactionMiddleware` now wrap it in turn (the next bullet has the full order). A thrown 404 or 500 is answered by the error middleware without ever reaching the route, so a `Vary: X-Screen` set further in would be missing from exactly the responses a shared cache is most likely to keep.
- Registers core routes directly — `GET /<slug>/` (Domů), `GET /profil`, the pairing routes `POST /profil/propojit`, `GET /profil/propojit/stav` and `POST /profil/odpojit` (below), the offline page `/offline` and the worker's `/precache.json` — then iterates `$event->features` and registers one module per enabled feature.

The full middleware order, innermost to outermost, is in [http.md](http.md); the source `Kernel::programSource()` checks is described in [program-provider.md](program-provider.md), and the pairing routes in [auth-and-session.md](auth-and-session.md).

## Event config and content

`EventConfig` (`src/EventConfig.php`) loads and validates `events/<slug>/config.php` (requires `name`, `colors`, `features`; refuses an event that enables `info` without `push`, because Informace lists the sent notifications; validates `dates`, `website` and `csp`; also carries arbitrary `raw` data via `get()`, and `dir` for locating fixtures/content). `EventConfig::content(string $name)` loads `events/<slug>/content/<name>.php`, a plain PHP file returning an array — these are per-event, not code.

What `content/links.php` and `content/emergency.php` hold, and where they are shown, is in [info.md](info.md).

## Modules and the tab bar

Modules implement `App\Module\ModuleInterface` (`key()`, `menuItem()`, `registerRoutes()`) and live in `src/Module/`. `ModuleRegistry::classFor($feature)` maps a feature-flag string (from the event's `features` array in its config) to a module class — this is how features are toggled per event: `info` (`InfoModule`), `map` (`MapModule`), `handbook` (`HandbookModule`, no tab of its own: the handbook is the first of Informace's quick links), `programs` (`ProgramsModule`) and `push` (`PushModule`). The tab bar is Domů (the event's `shortName` with its `menuLogo`, or the `fa-home` icon) followed by the enabled modules' items in their `order`: Program (10), Místa (20), Informace (30).

The push module itself, with the admin page and the subscription store, is in [push.md](push.md).
