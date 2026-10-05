# Deployment

One instance of the app serves every event. The event is the first path segment
(`/obrok27/`, `/korbo26/programy`); `/` is a picker of the events whose config says
`listed => true`. There is nothing to deploy per event except its directory.

Login is by TIE code only. There is no SkautIS app to register and no `soap`
extension to install.

The primary route is Docker; a classic Apache/PHP host is described at the end.

## Docker

The stack is two containers: `app` (php-fpm, built from `Dockerfile`) and `web`
(nginx, `docker/nginx.conf`). nginx serves `www/` itself and hands every other
request to `www/index.php`.

**Build and deploy from one checkout.** PHP renders the templates that are baked into
the image, while nginx serves `style.css`, `app.js` and the logos from the host's
`./www` bind mount. Pulling without `--build`, or building the image from a different
checkout than the one next to `docker-compose.prod.yml`, pairs new assets with old
templates or the reverse.

1. Create `.env` next to `docker-compose.prod.yml` from `.env.example` (see below).
   It is listed in `.dockerignore`: it never enters the image, `env_file: .env` passes
   it to the container's environment at run time.
2. `APP_RELEASE=$(git rev-parse --short HEAD) docker compose -f docker-compose.prod.yml up -d --build`.
   `APP_RELEASE` names the release in Sentry; without it the image says `dev`. A value
   in `.env` wins over the build argument because `env_file` is applied at run time,
   and so does an empty one: keep `APP_RELEASE` out of `.env` (`.env.example` has it
   commented out), or Sentry shows `unknown` instead of the hash.
   **Before the first deploy of this version, back up `var/push.sqlite`** (see the `var`
   volume below): its first request rewrites the subscriptions table.
3. **Never publish php-fpm's port 9000** (no `ports:` on `app`): it speaks FastCGI with no authentication, so anyone who reaches it can run PHP. Only nginx reaches it, over the compose network.
4. The stack publishes port `8080` (`8080:80` in the compose file). Put a reverse
   proxy in front of it; change the left-hand number if something else holds 8080.
   Set `TRUSTED_PROXY_COUNT=1` in `.env` behind that proxy (it must send
   `X-Forwarded-For`). Without it every reader shares the proxy's address and the TIE
   login limit — 60 wrong codes per address and event per 10 minutes — becomes one
   limit for everybody.
5. Open `/`, then each event's screens (`/<slug>/`, `/<slug>/programy`, ...).
   Which screens exist depends on `features` in the event's config. On a
   production event check that the programme matches reality and not the demo
   fixtures (see `PROGRAM_PROVIDER_<SLUG>` below).

What the image does, and why:

- **Environment reaches PHP.** php-fpm clears the environment of its workers by
  default and PHP's `variables_order` would leave `$_ENV` empty. `docker/php-fpm.conf`
  sets `clear_env = no` and `docker/php.ini` sets `variables_order = "EGPCS"`. Without
  them every variable below would read as unset and the app would silently run on
  its defaults (stub programmes, empty admin token).
- **The `var` volume** (`/app/var`) holds `push.sqlite` (push subscriptions and the
  log of sent messages, which is what News shows), the compiled Twig cache,
  `sessions/` (see below) and `cache/<slug>/`, the last kissj answers of each event on
  `kissj` (one JSON file per entry, replaced atomically). `cache/` is safe to delete at
  any time: the next request per event asks kissj again. The entrypoint leaves it alone
  on purpose, so a kissj outage right after a redeploy still has data to fall back on.
  Back up `push.sqlite`; the subscriptions and the message log
  are the only durable state, sessions can be lost at the price of a re-login.
  The database runs in WAL mode, so `push.sqlite-wal` and `push.sqlite-shm` sit next to
  it: a backup copies all three, or runs `sqlite3 push.sqlite ".backup /path/push-backup.sqlite"`.
  The schema is migrated in place by the first request that opens the file after a
  deploy, one transaction per step (`PRAGMA user_version` records the step reached); a
  failed step is rolled back whole while the steps before it stay committed, the request
  answers 500 and reaches Sentry, and the next request tries again from that step.
  `PUSH_DB_PATH` may be relative: it is resolved against the project root, so
  `var/push.sqlite` is `/app/var/push.sqlite` whatever php-fpm's working directory is.
- **Sessions survive a restart.** `docker/php.ini` sets `session.save_path` to
  `/app/var/sessions` (inside the volume) and `session.gc_maxlifetime` to 30 days
  (2592000 s), and the entrypoint creates that directory owned by www-data on every
  start. `Session` issues a persistent 7-day cookie (it outlives a browser restart), so
  the server side has to keep the data at least as long; PHP's default of 1440 s and
  a `/tmp` save path in the container would log a participant out after 24 minutes
  idle and on every redeploy.
- **The Twig cache is dropped on every container start** (`docker/entrypoint.sh`)
  and Twig always re-checks template mtimes (`auto_reload`, one stat per template), so
  an edited template is never served from a stale compiled copy. The start-up drop only
  clears files of templates that no longer exist.
- **opcache** is on with `validate_timestamps=0` (measured: about 4x faster, 35 ms
  against 134 ms on the Program screen). Code changes therefore need a new image, which
  is the only way the code changes anyway.
- **Capacity.** `docker/php-fpm.conf` runs `pm = dynamic` with `pm.max_children = 20`
  (`start_servers` 4, spare 2–6) instead of the default 5, so a few readers waiting on
  a slow kissj cannot stall everybody. nginx waits `fastcgi_read_timeout 45s` for PHP.
  The app's outbound calls carry their own timeouts (10 s to kissj), so a request
  normally ends well before that; one that does not — a push send to a large audience
  can — is answered with nginx's 504 while PHP finishes. PHP's `max_execution_time`
  (30 s) counts CPU time only and does not guard against a slow network; when it does
  strike it is a fatal error, not the app's error page. `docker/php.ini` sets
  `display_errors = Off`, `log_errors = On` and `expose_php = Off`, so a fatal error
  never reaches the reader: it goes to `docker logs` and, when configured, Sentry.
- **`GET /health`** answers `200 {"ok":true}` when the push database opens and
  `503 {"ok":false}` when it does not, with `Cache-Control: no-store`. Point the
  reverse proxy's health check or an uptime probe at it; it migrates nothing, logs
  nothing and is not reported to Sentry.
- **What reaches Sentry.** An issue for every exception the error handler sees (not a
  404 or 405), for every kissj failure hidden behind a stale cache entry, for every app
  log line at warning or above (such as `push.failed`, one per send in which some
  notifications were refused or failed), for every throwable the push sender swallows,
  and for PHP warnings and deprecations (the SDK's default integrations stay on, as in
  kissj). A log record that carries `['exception' => $e]` is **not** filed with its
  exception by `LogToSentryIssueHandler`, so code reports an exception through
  `Telemetry\Collector::collect()`, never through the logger. Routine log lines such as
  `push.sent` stay on stdout. Push and cache counts are span data (`push.send`, `push.welcome`,
  `push.subscribe`, `push.unsubscribe`, `program.cache`), not issues. A request that
  matched no route is the one transaction `unmatched`.
- **nginx serves `.webmanifest` as `application/manifest+json`** (`types` block in
  `docker/nginx.conf`); its `mime.types` does not know the extension.
- **nginx mirrors `www/.htaccess`**: versioned assets get a year of `immutable` cache,
  `sw.js` is `no-cache`, static files carry the same security headers PHP sets on
  everything it answers, directories are never listed, `*.php` other than the front
  controller is a 404, and text types are gzipped.
- **Assets carry a content hash.** `templates/_layout.twig` links the stylesheet and
  every script through `asset_version()`, which appends `?v=` and the first 8 hex
  characters of the file's sha256 (`App\Http\AssetVersion`, memoised per PHP process by
  path, mtime and size). A changed file gets a new URL on its own; there is nothing to
  bump. Event logos and favicons are not versioned: one replaced in place reaches a
  returning visitor only because the service worker revalidates it past the year-long
  HTTP cache (its fetch bypasses that cache), never through the cache itself. Upload a
  replacement under a new name and change `assets` in the event's config.
- `www/sw.js` must stay at the docroot root. The worker is registered with scope
  `/<slug>/`, which is only allowed for a script at or above that path.
- **The service worker is the offline copy.** It precaches what `/<slug>/precache.json`
  lists into `eventapp-<slug>-<version>`. A deploy that changes any stylesheet or
  script (or adds or removes a listed file) changes the version, and a returning
  reader picks it up one of two ways, neither needing a reload. A changed `sw.js` goes
  the browser's way: `sw.js` is `no-cache`, so the browser sees the new script, installs
  it, and `skipWaiting` + `clients.claim` let it take over. A changed list under an
  unchanged `sw.js` — the usual deploy, since `sw.js` is never versioned — never makes
  the browser reinstall; instead the running worker, once per lifetime, after a page
  came from the network, fetches `precache.json` past the HTTP cache, fills a cache for
  the new version, switches to it and deletes the old one. Either way a failed fill
  keeps the reader on the previous cache. `precache.json` and `/<slug>/offline` are app
  routes; nothing in nginx or `.htaccess` names them. If the worker misbehaves, the
  rollback is a `sw.js` with only the `push` and `notificationclick` handlers, an
  `install` that calls `self.skipWaiting()`, and an `activate` that deletes every
  `eventapp-<slug>-*` cache and then calls `self.clients.claim()`. Without those two
  calls the rollback waits until every window of the event is closed — in an installed
  app that can be days — while the misbehaving worker keeps serving.

### TLS

nginx speaks plain HTTP. Terminate TLS in the reverse proxy in front of it and have it
send `X-Forwarded-Proto: https`; nginx turns that into `HTTPS=on` for PHP, which is
what makes the session cookie `Secure`. **HTTPS is required for push notifications**,
the offline copy and the install offer: a service worker registers only on a secure origin.

Order matters when you switch HTTPS on. Issue the certificate and check that
`https://<domain>/` answers first, then enable the HTTP to HTTPS redirect in the
proxy, then HSTS (`Strict-Transport-Security: max-age=31536000` — a browser remembers
it for a year, there is no going back). A redirect in front of a certificate that does
not exist yet is a redirect loop.

### Content-Security-Policy

PHP sends an enforced `Content-Security-Policy` on every page it answers (on Docker and
on Apache alike), with a fresh nonce per request: a `<script>` without that nonce does
not run. `connect-src` is `'self'` by default — kissj is called by PHP, never by the
browser — and an event's `csp` key may extend it like the other directives.
An event's `theme.font-url` origin is added to `style-src` and `font-src` automatically,
and a published map's origin to `frame-src`. Anything else an event embeds — photos or
videos from another site — is named in its `config.php` under `csp`, as `https://`
origins without a path; an unknown directive or a malformed origin stops the event from
booting:

```php
'csp' => [
    'img-src' => ['https://photos.example'],
    'frame-src' => ['https://video.example'],
],
```

### `.env`

Instance-wide:

| Variable | Meaning |
| --- | --- |
| `APP_DEBUG` | `0` in production, exactly. Only `1`, `true` and `on` turn debugging on, but debug pages print stack traces with paths and configuration, so leave the value at `0`. |
| `KISSJ_BASE_URL` | kissj root, e.g. `https://kissj.net`. Required as soon as any event uses `kissj`; without it that event's pages fail with a `RuntimeException`. |
| `PROGRAM_CACHE_TTL` | Seconds a kissj answer (the programme list, one participant's registrations) is served from `var/cache/<slug>/` before kissj is asked again; whole seconds, default `300`. When kissj fails, the last answer is served however old it is and the failure goes to Sentry, so the Program screen keeps working through an outage. `0` asks kissj on every request and still falls back. Events on `stub` are never cached. |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` | Push keys: `php bin/generate-vapid.php` (run it in any PHP 8.3 container with the repo mounted; `bin/` is not in the image). One pair for the whole instance. **Required:** every event has push, and an event refuses to boot when a key is missing or is not a base64url P-256 key (public: 65 bytes starting `0x04`; private: 32 bytes). Every request to that event then fails with a bare 500 before the app exists; the `RuntimeException` naming the variable is in the PHP log (`docker logs`) and in Sentry, not on the page. |
| `VAPID_SUBJECT` | `mailto:` contact for the push services. Must start with `mailto:` or `https://`; checked at boot like the keys. |
| `PUSH_DB_PATH` | Defaults to `var/push.sqlite`. Holds the push subscriptions and the message log that News is built from. |
| `SENTRY_DSN` | Sentry project DSN. Empty (the default) switches error reporting and tracing off entirely. |
| `SENTRY_TRACES_SAMPLE_RATE` | Share of requests traced, `0`..`1`; `.env.example` suggests `0.2`. Missing or empty means `0`. |
| `SENTRY_PROFILES_SAMPLE_RATE` | Share of traced requests profiled, `0`..`1`; leave at `0` unless the excimer extension is installed. |
| `APP_RELEASE` | Release name in Sentry. The Docker build sets it from the deploy command; leave it out of `.env` (it is commented out in `.env.example`), because any line here, even an empty `APP_RELEASE=`, overrides the build's hash. Uncomment it only to override that. |
| `TRUSTED_PROXY_COUNT` | Reverse proxies in front of the app whose `X-Forwarded-For` entries are trusted. `1` behind the Docker stack's proxy, `0` (default) when PHP sees the readers directly. Wrong here, the TIE login limit is either global (too low) or spoofable (too high). |
| `PUSH_ENDPOINT_HOSTS` | Extra push-service hosts a browser subscription may name, comma-separated, `*.example` allowed. Empty in production: the known services (Google, Apple, Mozilla, Microsoft, Samsung) are built in. Narrowing the list deletes the stored subscriptions on hosts no longer allowed at the next send to them. |

Per event, each name suffixed with the slug in upper case and `-` turned into `_`
(`obrok27` becomes `OBROK27`). Only the events that need a value need a line.

| Variable | Meaning |
| --- | --- |
| `PROGRAM_PROVIDER_<SLUG>` | **The most important line per event.** `stub` (the default when the line is missing) serves the demo data from `events/<slug>/fixtures/`; `kissj` reads the real programme from the registration API. A missing line boots fine and shows the demo programme as if it were real, with no error and no log entry. Production events stay on `stub` until kissj serves the contract endpoints (see below). |
| `KISSJ_API_KEY_<SLUG>` | The event's programme API key. kissj tells the event from the key, so no slug is configured there. Required when the provider is `kissj`. |
| `ADMIN_TOKEN_<SLUG>` | Long random string (`openssl rand -hex 24`). The organisers open `/<slug>/admin/notify?token=<token>`; the link logs that browser in for 24 hours and redirects to `/<slug>/admin/notify`, and the pages after it carry no token (the forms carry a CSRF field). Re-open the link after a day. Empty means the page is refused, also for a browser that is already logged in. Every event has the page. |

**Production events stay on `PROGRAM_PROVIDER_<SLUG>=stub` until kissj serves the
endpoints of `docs/kissj-contract.md`.** Do not switch one to `kissj` before that.
Sending a notification to one programme also needs kissj's
`GET /v3/programme/{id}/participants` once the event is on `kissj`: it names the TIE
codes the message goes to.

Per-event variables by event (`KISSJ_API_KEY_<SLUG>` is only needed once an event is on
`kissj`):

| Event | Variables |
| --- | --- |
| `obrok19` | `PROGRAM_PROVIDER_OBROK19`, `KISSJ_API_KEY_OBROK19`, `ADMIN_TOKEN_OBROK19` |
| `obrok27` | `PROGRAM_PROVIDER_OBROK27`, `KISSJ_API_KEY_OBROK27`, `ADMIN_TOKEN_OBROK27` |
| `korbo26` | `PROGRAM_PROVIDER_KORBO26`, `KISSJ_API_KEY_KORBO26`, `ADMIN_TOKEN_KORBO26` |
| `navigamus25` | `PROGRAM_PROVIDER_NAVIGAMUS25`, `KISSJ_API_KEY_NAVIGAMUS25`, `ADMIN_TOKEN_NAVIGAMUS25` |
| `miquik26` | `ADMIN_TOKEN_MIQUIK26` only (unlisted; dev fixtures from the lecture workbook, no kissj data yet) |

## Tests and CI

`.github/workflows/ci.yml` runs `vendor/bin/phpunit` on PHP 8.3 and 8.4 on
`ubuntu-latest`, including the `browser` suite: `symfony/panther` drives the runner's own
Chrome through its `chromedriver` (`PANTHER_CHROME_DRIVER_BINARY`, found with `command -v
chromedriver`, falling back to `$CHROMEWEBDRIVER/chromedriver`), and `PANTHER_NO_SKIP=1`
makes a missing Chrome a failure instead of a skip. Locally the browser tests run against
`selenium/standalone-chrome`:
`docker compose --profile browser run --rm --use-aliases test vendor/bin/phpunit --group browser`
(`docker compose --profile browser stop chrome` afterwards). The `test` service runs as
root, as `slim` does, so a browser run leaves root-owned compiled templates under
`var/twig/`; if a later run as your own user cannot write there, remove them from a
container: `docker run --rm -v "$PWD":/app -w /app php:8.3-alpine rm -rf var/twig/*`.
There is no Node anywhere in the project. Composer under the `php:8.3-alpine` image
needs `--ignore-platform-req=ext-gmp --ignore-platform-req=ext-zip` (php-webdriver
declares `ext-zip`, which Chrome never needs); the production image installs
`--no-dev` and is unaffected.

## Adding an event

1. Copy `events/obrok27/` to `events/<slug>/` and edit `config.php` and `content/`.
   The slug matches `/^[a-z0-9-]+$/` and ends in a two-digit year (`korbo26`).
2. Set `listed` (shown in the picker or not) and `dates` (`start`, `end` as
   `YYYY-MM-DD`; the picker splits upcoming from past on `end`) in the config.
3. Copy `www/events/obrok27/` to `www/events/<slug>/` and replace the logos, favicons
   and manifest (its `id`, `start_url`, `scope` and `shortcuts` name the slug; a shortcut
   only for a screen the event enables). Generate the maskable icon from the 512 one on
   the manifest's `background_color` — ImageMagick in a throwaway container, there is no
   image library in the PHP image:
   `docker run --rm -e HOST_UID="$(id -u)" -e HOST_GID="$(id -g)" -v "$PWD/www/events/<slug>":/w -w /w alpine:3 sh -c 'apk add --no-cache imagemagick >/dev/null && magick android-chrome-512x512.png -resize 80% -background "<background_color>" -gravity center -extent 512x512 -flatten -strip maskable-512.png && chown "$HOST_UID:$HOST_GID" maskable-512.png'`
   (`apk add` needs root, hence the `chown` rather than `--user`)
   and look at it: Android crops it to a circle, so the mark must sit inside the middle
   80 %. The copied config's `assets.pinnedTab` names obrok27's `safari-pinned-tab.svg`:
   point it at your own file or remove the key.
4. Add the per-event variables above to `.env`.
5. Rebuild: `APP_RELEASE=$(git rev-parse --short HEAD) docker compose -f docker-compose.prod.yml up -d --build`. The new
   directory is found on the next request; there is no registry to edit.

To fill the fixtures from kissj's responses use `php bin/kissj-fixtures.php <slug>`.

## Classic PHP hosting (Apache)

Every event is still served by the one instance, so this is a single installation too.

### Requirements

- PHP >= 8.3 with `json`, `pdo_sqlite` and `gmp` or `bcmath` (push notifications).
- Once `SENTRY_DSN` is set, `sentry/sentry` also needs the `curl` and `mbstring` extensions
  (`ext-curl`, `ext-mbstring`).
- **opcache** on. Without it the app is about 4x slower. Check `opcache.enable=1` in
  `phpinfo()`.
- **PHP-FPM pool and timeouts**, where the host lets you set them: `pm.max_children`
  about 20 (the default 5 lets a few slow kissj answers stall everybody), and a
  FastCGI/proxy timeout of at least 45 s, as in the Docker stack. Set
  `display_errors = Off` and `log_errors = On` so a PHP fatal error never reaches a
  reader, and `zend.exception_ignore_args = On` (PHP's production default) so a TIE
  code passed as a call argument never reaches a Sentry stack trace. `/health`
  (above) works here too.
- Apache with mod_rewrite and a docroot you can point at `www/`. mod_headers,
  mod_expires and mod_deflate are recommended: `www/.htaccess` uses them for security
  headers, asset caching and compression. Every block is in `<IfModule>`, so a host
  without one still works, only without that benefit.
- HTTPS, as above.

### Steps

1. Run `composer install --no-dev` locally and upload the whole project **except**
   `.env`, `var/` and `.git/`.
2. Point the domain's docroot at `www/`.

   On a host with a fixed `htdocs`, put the contents of `www/` in `htdocs` and the rest
   of the project **one level up, outside the docroot**, and adjust the `require` in
   `htdocs/index.php` to the new relative path of `vendor/autoload.php`.

   Everything on this list must stay out of what the server serves: `.env`, `var/`,
   `vendor/`, `src/`, `events/`, `tests/`, `composer.json`, `composer.lock`, `docs/`,
   `bin/`. `.env` holds the admin tokens, the kissj keys and the VAPID keys; a
   downloadable `.env` compromises the whole instance.

   `www/.htaccess` moves to `htdocs` together with the contents of `www/`. Forget it
   and only `/` works, **every other route is a 404**, and `Options -Indexes` and the
   security headers are gone too.
3. Create `.env` in the project root (outside the docroot) from `.env.example`, with
   the variables above. `APP_DEBUG=0`, always.
4. Create a writable `var/` outside the docroot. Inside it `var/push.sqlite` would be
   downloadable over HTTP, and with it every push endpoint and subscriber key.
   Permissions `750` (`700` on shared hosting), **not `775`**: group-writable means
   writable for other tenants of the server. `var/push.sqlite` itself `0600`. The owner
   must be the user PHP runs as.
   `var/` must stay writable for PHP, not just `var/push.sqlite`: WAL mode creates
   `push.sqlite-wal` and `push.sqlite-shm` next to the file. A backup copies all three
   (or runs `sqlite3 push.sqlite ".backup …"`). Back up before deploying this version:
   its first request rewrites the subscriptions table.
5. On an **update** of a running instance there is nothing to bump: a changed stylesheet
   or script gets a new `?v=` content hash by itself, which is what makes
   `www/.htaccess`'s year of `immutable` safe. Twig notices a changed template on its
   own, so `var/twig/` needs no clearing.
   Sessions: PHP's defaults (`session.gc_maxlifetime=1440`, often a shared `/tmp`) drop a
   login after 24 idle minutes although the cookie lasts 7 days. Set
   `session.gc_maxlifetime` to at least `604800` and, if the host shares `/tmp`, a
   private `session.save_path`.

### GitHub Actions deployment over FTP

All workflows use the PHP version in `.github/php-version`. Change that
file to update tests, dependency auditing and deployment together.
`ci.yml` runs the tests and audits the locked dependencies.
It runs on pull requests, non-master pushes and manually, and is reusable through
`workflow_call`. `deploy.yml` calls it on master pushes or a manual run, then
runs `just release` to prepare `release/` and `just deploy` to sync it using `lftp`.
Only master can deploy. CI installs `just` and `lftp` through `apt`.

For a local release, install [just](https://just.systems/man/en/packages.html)
and `lftp`, then run `just release` followed by `just deploy` with the FTP variables
below exported in your shell. PHP and Composer must also be available. Plain
`just` lists the recipes. Release preparation replaces any previous `release/`;
deployment uses the prepared bundle without rebuilding it.

Add these repository secrets, using the same names as `SkautDevs/web`:

- `FTP_HOST`: the FTP URL, e.g. `ftp://ftp.example.com` (TLS is required).
- `FTP_USER`: the FTP username.
- `FTP_PASS`: the FTP password.

Use an FTP account dedicated to eventApp whose public directory is `www/`.
The release contains `src/`, `templates/`, `events/`, `vendor/` and `www/`, including
`www/.htaccess`. The private directories are uploaded beside `www/`.

Create `.env` once in that private root from `.env.example`. It holds `APP_DEBUG=0`,
the programme provider settings and kissj API keys, admin tokens and push keys
described above. Also create writable `var/` as in the hosting setup steps.
The sync mirrors `release/` directly to the FTP root and deletes stale files.
It uses up to eight parallel transfers and logs file operations to help diagnose
slow deployments.
It excludes `.env`, `var/`, `__log/`, `tmp/`, `www/.well-known/`,
`www/.user.ini` and `www/cgi-bin/` to preserve runtime data and hosting settings.
Changes to
`www/.htaccess` must be committed because deployment replaces that file.

Follow-up: update the [SkautDevs/web FTP pipeline](https://github.com/SkautDevs/web/blob/main/Makefile)
to add `--delete` to `mirror -R dist www`, with exclusions for hosting-managed files,
so removed assets are also deleted from the server.

### HTTP to HTTPS redirect

The redirect is deliberately not in the repository. Once the certificate works, add
**above** the front controller block in `www/.htaccess`:

```apache
RewriteCond %{HTTPS} !=on
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
```

Behind a host that terminates TLS on a proxy `%{HTTPS}` is always `off`; test
`%{HTTP:X-Forwarded-Proto} !=https` instead. The wrong condition is an endless
redirect loop. Then HSTS, inside `<IfModule mod_headers.c>`:

```apache
Header always set Strict-Transport-Security "max-age=31536000"
```

Only after everything works over HTTPS.

The session cookie's `Secure` flag is not a step: `Session` sets `secure` from
`$_SERVER['HTTPS']`. Behind a proxy that terminates TLS the web server has to set
`HTTPS=on` itself; on Apache, `SetEnvIf X-Forwarded-Proto https HTTPS=on` does it.
Without that the cookie is sent without `Secure`.

### Push notifications

1. `php bin/generate-vapid.php` and copy both keys into `.env`.
2. Set `ADMIN_TOKEN_<SLUG>` per event.
3. `www/sw.js` must stay at the docroot root; moving it into a subdirectory narrows
   its scope, and already registered visitors stay on an orphaned registration.
