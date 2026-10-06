# Deployment

One instance of the app serves every event. The event is the first path segment
(`/obrok27/`, `/korbo26/programy`); `/` is a picker of the events whose config says
`listed => true`. There is nothing to deploy per event except its directory.

Login is by TIE code only. There is no SkautIS app to register and no `soap`
extension to install.

Production is a classic Apache/PHP host fed by GitHub Actions over FTP: **every push to
`master` deploys**. A Docker stack (php-fpm and nginx) is described at the end as the
alternative for a host of your own.

## Production: GitHub Actions over FTP

### What a push to master does

`.github/workflows/deploy.yml` runs on every push to `master` (and on a manual run; only
`master` can deploy, and a newer push cancels a deploy still running). It first calls
`.github/workflows/ci.yml` as its gate: the whole PHPUnit run including the `browser`
suite — `symfony/panther` drives the runner's own Chrome through its `chromedriver`, and
`PANTHER_NO_SKIP=1` turns a missing Chrome into a failure, so the gate never passes on
skipped tests — plus `composer audit --locked`, which fails on a known-vulnerable
package. Only when both pass does it run `just release` (a `--no-dev` Composer install
from `composer.lock` with an optimised autoloader, copied with `src/`, `templates/`, `events/` and `www/` into
a fresh `release/`) and `just deploy` (an `lftp` mirror of `release/` onto the FTP root).

Every workflow takes its PHP version from `.github/php-version` — currently **8.5** —
so tests, the dependency audit and the release's `vendor/` are built on the PHP the host
runs. Change that file to move all three together. `ci.yml` also runs on pull requests,
on pushes to other branches and by hand. The deploy job in `deploy.yml` (not `ci.yml`)
installs `just` and `lftp` through `apt`.

For a local release, install [just](https://just.systems/man/en/packages.html) and
`lftp`, then run `just release` followed by `just deploy` with the FTP variables below
exported in your shell. PHP and Composer must also be available. Plain `just` lists the
recipes. Release preparation replaces any previous `release/`; deployment uses the
prepared bundle without rebuilding it.

Add these repository secrets, using the same names as `SkautDevs/web`:

- `FTP_HOST`: the FTP URL, e.g. `ftp://ftp.example.com` (TLS is required).
- `FTP_USER`: the FTP username.
- `FTP_PASS`: the FTP password.

Use an FTP account dedicated to eventApp whose public directory is `www/`. The release
contains `src/`, `templates/`, `events/`, `vendor/` and `www/`, so the private
directories land beside the docroot and never inside it. `bin/`, `tests/`, `docs/` and
the Composer files are not uploaded. The mirror deletes stale files and runs up to eight
parallel transfers with every file operation logged, to help diagnose a slow deploy. It
**excludes** `.env`, `var/`, `__log/`, `tmp/`, `www/.well-known/`, `www/.user.ini` and
`www/cgi-bin/`, so the runtime data and the hosting's own files survive every deploy.
`www/.user.ini` is therefore where host PHP settings belong (such as `session.auto_start`
below), on hosts that read it. **Everything else at the FTP root that is not in the
release is deleted by the mirror's `--delete`** on the next deploy: keep backups (a copy
of `push.sqlite`) and throwaway scripts out of the FTP root, or inside `var/`.

`www/.htaccess` is uploaded with the rest and is what the host reads: the front
controller rule, `Options -Indexes`, the security headers, the year of `immutable` on
versioned assets, `no-cache` on `sw.js` and compression. A change to it must be
committed, because the next deploy replaces the file on the server.

Follow-up: update the [SkautDevs/web FTP pipeline](https://github.com/SkautDevs/web/blob/main/Makefile)
to add `--delete` to `mirror -R dist www`, with exclusions for hosting-managed files,
so removed assets are also deleted from the server.

### Host requirements

- PHP **8.5** (the version in `.github/php-version`: the uploaded `vendor/` was resolved
  on it; the code itself runs on 8.3 and up) with `json`, `mbstring`, `pdo_sqlite`,
  `openssl` and `curl` — `minishlink/web-push` requires `mbstring`, `openssl` and
  `curl`, and Sentry needs `curl` and `mbstring` too — and `gmp` or `bcmath`, which make
  push encryption fast (without either the library still works and advises installing
  one; the app drops that advice from its logs).
- **opcache** on. Without it the app is about 4x slower. Check `opcache.enable=1` in
  `phpinfo()`.
- **`session.auto_start` off** (PHP's default). It is the one session setting the app
  cannot change at run time; everything else about sessions it sets itself (below).
  If the host turns it on, switch it off in `www/.user.ini`, which deploys never touch.
- **PHP-FPM pool and timeouts**, where the host lets you set them: `pm.max_children`
  about 20 (the default 5 lets a few slow kissj answers stall everybody), and a
  FastCGI/proxy timeout of at least 45 s, as in the Docker stack. Set
  `display_errors = Off` and `log_errors = On` so a PHP fatal error never reaches a
  reader, and `zend.exception_ignore_args = On` (PHP's production default) so a TIE
  code passed as a call argument never reaches a Sentry stack trace.
- Apache with mod_rewrite and the docroot on `www/`. mod_headers, mod_expires and
  mod_deflate are recommended: `www/.htaccess` uses them for security headers, asset
  caching and compression. Every block is in `<IfModule>`, so a host without one still
  works, only without that benefit.
- HTTPS (see [TLS](#tls) and the redirect below).

### First setup on the host

1. Create `.env` once in the private root (next to `src/`, outside `www/`) from
   `.env.example`, with the variables below. `APP_DEBUG=0`, always. `.env` holds the
   admin tokens, the kissj keys and the VAPID keys; a downloadable `.env` compromises the
   whole instance, so it must never sit under `www/`. The mirror never touches it.
2. Create **`var/`** in the private root, writable by the user PHP runs as. PHP keeps
   everything it writes there: `sessions/` (created 0700 on first use), `cache/<slug>/`
   (the kissj answers), `push.sqlite` (push subscriptions and the message log, the only
   durable state) and `twig/` (compiled templates). `var/` is excluded from the mirror,
   so it survives every deploy. Permissions `750` (`700` on shared hosting), **not
   `775`**: group-writable means writable for other tenants of the server.
   `var/push.sqlite` itself `0600`. Inside the docroot `push.sqlite` would be
   downloadable over HTTP, and with it every push endpoint and subscriber key.
   WAL mode creates `push.sqlite-wal` and `push.sqlite-shm` next to the file, which is
   why the whole directory must be writable; a backup copies all three, or runs
   `sqlite3 push.sqlite ".backup /path/push-backup.sqlite"`.
3. Check once that PHP's `REMOTE_ADDR` is the reader's address (print it from a
   throwaway script, or look at `phpinfo()`): the TIE login and the push subscribe
   limits count by it. A host that puts its own proxy in front of Apache must restore
   the address with `mod_remoteip`, or set `TRUSTED_PROXY_COUNT` (see
   [Proxies and the reader's address](#proxies-and-the-readers-address)).
4. Push a commit to `master` (or run the workflow by hand), then open `/` and each
   event's screens. On a production event check that the programme matches reality and
   not the demo fixtures (see `PROGRAM_PROVIDER_<SLUG>` below).

**Back up `var/push.sqlite` before deploying this version.** The first request that
opens it migrates the schema in place (steps 3–5: a strike counter on subscriptions, the
subscribe limit's table, and the message log rebuilt with nullable counts and a `failed`
column), one transaction per step; `PRAGMA user_version` records the step reached, a
failed step is rolled back whole while the steps before it stay committed, the request
answers 500 and reaches Sentry, and the next request tries again from that step.

**Deploy this version in a quiet moment, ideally a short maintenance window.** Two things
make it different from a routine update:

- It changes `vendor/` (`minishlink/web-push` 9 → 10), and the FTP mirror uploads `src/`
  before `vendor/`, so requests during the upload can run the new code against the old
  library and fail. They stop failing once the upload completes; the first request after it
  also runs migration steps 3–5 above.
- **Readers logged in on the previously deployed version are logged out once.** The
  session store moved to `var/sessions`, where their old session ID is unknown; they log
  in again with their TIE code. If the host ever set `session.cookie_domain`, the old
  domain-wide cookie would be sent before the new host-only one and PHP reads the first,
  so a login would not stick: clear that setting, and a reader whose login does not stick
  clears the site's cookies.

### Updates

There is nothing to bump: a changed stylesheet or script gets a new `?v=` content hash by
itself, which is what makes `www/.htaccess`'s year of `immutable` safe. Twig re-checks
template mtimes on every render, so `var/twig/` needs no clearing. `var/cache/` is safe to
delete at any time (see [kissj](#kissj-and-its-cache)).

### Sessions

Nothing about sessions depends on the host's `php.ini`. `App\Session` sets, before every
start, the files handler with `session.save_path` at `<app root>/var/sessions` (or
`SESSION_PATH`), created 0700; `gc_maxlifetime` of 30 days with PHP's own garbage
collection at 1 in 1000 requests (a private directory is outside any distribution's
cleanup cron); strict mode and cookie-only IDs; no cache limiter (the app itself sends
`Cache-Control: no-store, no-cache, must-revalidate` on every HTML page and screen
fragment, with a cookie or without); and a host-only cookie `eventapp` on `/`,
`HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS. The cookie lasts 30 days and slides: every
full page a logged-in reader gets re-issues it with a fresh expiry, and the session file
is touched at most once a day so the garbage collector measures from the last visit, not
from the login. Sessions are read with `read_and_close` and reopened only for the moment a
value changes, so a slow kissj call never makes the reader's next request wait for the
session lock; a visit without the cookie starts no session and writes no file. Neither
does a read under a cookie the store does not hold (deleted, collected, invented): with
the app's own files store the session file is checked first and nothing is started, so a
replayed bogus cookie cannot fill `var/sessions`. The first write under such a cookie gets
a new ID and cookie, which is what strict mode does. On the host's own handler (the last
fallback below) the check cannot be made, and such a read gets a new cookie and an empty
session.

If `var/sessions` cannot be created or written, or is a symlink, the store moves to a
private directory under the system temp dir (`eventapp-sessions-<8 hex of the app
root's sha256>`), accepted only if it is no symlink, belongs to PHP's user and grants
nothing to group or others; failing that too, the host's own handler and path are left
untouched — and the cookie still slides, the file does not. Either fallback is reported
to Sentry once per PHP process. A temp-dir store may be emptied by the host's cleanup,
which logs readers out: fix `var/` rather than live with it. A `SESSION_PATH` outside the
app root gets the same ownership and permission check.

### Proxies and the reader's address

The TIE login limit (60 unknown codes per address and event per 10 minutes) and the push
subscribe limit (300 new subscriptions per address and event per 10 minutes) count by
`App\Http\ClientIp`: an IPv4 address as it is, an IPv6 one as its **/64** (a phone or a
home is handed a whole /64), an IPv4-mapped IPv6 address as the IPv4 address it carries.
`TRUSTED_PROXY_COUNT` makes the app take the address that many hops from the right end of
`X-Forwarded-For` — but **only on a connection from a loopback or private address**
(127/8, 10/8, 172.16/12, 192.168/16, `::1`, `fc00::/7`) or from one `TRUSTED_PROXIES`
names. Only the immediate peer is matched against those; the hops inside it are trusted
by the count alone. A reader who connects straight from the internet wrote any
`X-Forwarded-For` themselves and is counted by `REMOTE_ADDR`. Never put a `/0` in
`TRUSTED_PROXIES`: it trusts every connection, and the limits become spoofable.

On a typical FTP host PHP sees the readers directly: leave `TRUSTED_PROXY_COUNT=0`.

### The TIE login refuses cross-site requests

`POST /<slug>/profil/tie` and `/<slug>/profil/tie-logout` answer **403** with a short
Czech page ("Přihlášení se nepodařilo…" or "Odhlášení se nepodařilo…", with a link back to
`/profil`) unless the request comes from the site's own pages (`App\Http\SameOrigin`):
`Sec-Fetch-Site` decides when the browser sends it (`same-origin` and `none` pass); only
without it is `Origin` compared, **by host alone**, against the request's host. A reverse
proxy in front of the app must therefore pass the original `Host` (nginx:
`proxy_set_header Host $host;`), or a browser that sends no `Sec-Fetch-Site` cannot log in.

### The admin token and the access log

`/<slug>/admin/notify?token=<ADMIN_TOKEN_<SLUG>>` carries the token in its query string.
On the FTP host the access log is not ours to configure, and it **will contain the token
of every opened admin link**. **Rotate `ADMIN_TOKEN_<SLUG>` after the event**, and
whenever a link leaked: change it in `.env`, and send the organisers the new link.

### HTTP to HTTPS redirect

The redirect is deliberately not in the repository. Once the certificate works, add
**above** the front controller block in `www/.htaccess` (and commit it — a deploy replaces
the file):

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

### Without the pipeline

A manual upload follows the same layout: `composer install --no-dev` locally, upload
`src/`, `templates/`, `events/`, `vendor/` and `www/` (never `.env`, `var/` or `.git/`),
docroot on `www/`. On a host with a fixed `htdocs`, put the contents of `www/` in
`htdocs` and the rest **one level up, outside the docroot**, and adjust the `require` in
`htdocs/index.php` to the new relative path of `vendor/autoload.php`. `www/.htaccess`
moves with the contents of `www/`: forget it and only `/` works, **every other route is a
404**, and `Options -Indexes` and the security headers are gone too.

## `.env`

Instance-wide:

| Variable | Meaning |
| --- | --- |
| `APP_DEBUG` | `0` in production, exactly. Only `1`, `true` and `on` turn debugging on, but debug pages print stack traces with paths and configuration, so leave the value at `0`. |
| `KISSJ_BASE_URL` | kissj root, e.g. `https://kissj.net`. Required as soon as any event uses `kissj`; without it that event's pages fail with a `RuntimeException`. |
| `PROGRAM_CACHE_TTL` | Seconds a kissj answer (the programme list, one participant's registrations) is served from `var/cache/<slug>/` before kissj is asked again; whole seconds, default `300`. When kissj fails, the last answer is served however old it is, so the Program screen keeps working through an outage. `0` asks kissj on every request and still falls back. Events on `stub` are never cached. See [kissj](#kissj-and-its-cache). |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` | Push keys: `php bin/generate-vapid.php` (run it locally or in any PHP container with the repo mounted; `bin/` is neither in the FTP release nor in the image). One pair for the whole instance. **Required:** every event has push, and an event refuses to boot when a key is missing or is not a base64url P-256 key (public: 65 bytes starting `0x04`; private: 32 bytes). Every request to that event then fails with a bare 500 before the app exists; the `RuntimeException` naming the variable is in the PHP log and in Sentry, not on the page. |
| `VAPID_SUBJECT` | `mailto:` contact for the push services. Must start with `mailto:` or `https://`; checked at boot like the keys. |
| `PUSH_DB_PATH` | Defaults to `var/push.sqlite`; may be relative to the project root. Holds the push subscriptions and the message log that News is built from. |
| `SESSION_PATH` | Directory of the session files; defaults to `var/sessions` and, like `PUSH_DB_PATH`, may be relative to the project root. Leave it unset: the browser tests set it to keep their sessions out of the tree. A path outside the app root must belong to PHP's user and grant nothing to group or others, or the store falls back (see [Sessions](#sessions)). |
| `SENTRY_DSN` | Sentry project DSN. Empty (the default) switches error reporting and tracing off entirely. |
| `SENTRY_TRACES_SAMPLE_RATE` | Share of requests traced, `0`..`1`; `.env.example` suggests `0.05`. Missing or empty means `0`. |
| `SENTRY_PROFILES_SAMPLE_RATE` | Share of traced requests profiled, `0`..`1`; leave at `0` unless the excimer extension is installed. |
| `APP_RELEASE` | Release name in Sentry. The Docker build sets it from the deploy command; leave it out of `.env` there (it is commented out in `.env.example`), because any line here, even an empty `APP_RELEASE=`, overrides the build's hash. The FTP release carries no `.git/` and sets nothing, so on the FTP host the release is whatever this line says, and `unknown` without it. |
| `TRUSTED_PROXY_COUNT` | Reverse proxies in front of the app whose `X-Forwarded-For` entries are trusted — honoured only on a connection from a loopback or private address or one `TRUSTED_PROXIES` names. `1` behind the Docker stack's proxy, `0` (default) when PHP sees the readers directly, as on the FTP host. Wrong here, the login and subscribe limits are either global (too low) or spoofable (too high). |
| `TRUSTED_PROXIES` | Extra proxy addresses allowed to send `X-Forwarded-For` besides the loopback and private ones: comma-separated addresses or CIDR ranges, IPv4 or IPv6 (a CDN, a hosted balancer). A malformed entry is skipped. Never `/0`. Empty by default. |
| `PUSH_ENDPOINT_HOSTS` | Extra push-service hosts a browser subscription may name, comma-separated, `*.example` allowed. Empty in production: the known services (Google, Apple, Mozilla, Microsoft, Samsung) are built in. Narrowing the list deletes the stored subscriptions on hosts no longer allowed at the next send to them. |

Per event, each name suffixed with the slug in upper case and `-` turned into `_`
(`obrok27` becomes `OBROK27`). Only the events that need a value need a line.

| Variable | Meaning |
| --- | --- |
| `PROGRAM_PROVIDER_<SLUG>` | **The most important line per event.** `stub` (the default when the line is missing) serves the demo data from `events/<slug>/fixtures/`; `kissj` reads the real programme from the registration API. A missing line boots fine and shows the demo programme as if it were real, with no error and no log entry. Production events stay on `stub` until kissj serves the contract endpoints (see below). |
| `KISSJ_API_KEY_<SLUG>` | The event's programme API key. kissj tells the event from the key, so no slug is configured there. Required when the provider is `kissj`. |
| `ADMIN_TOKEN_<SLUG>` | Long random string (`openssl rand -hex 24`). The organisers open `/<slug>/admin/notify?token=<token>`; the link logs that browser in for 24 hours and redirects to `/<slug>/admin/notify`, and the pages after it carry no token (the forms carry a CSRF field). Re-open the link after a day. Empty means the page is refused, also for a browser that is already logged in. Every event has the page. **Rotate it after the event**: the opened links are in the host's access log (see [above](#the-admin-token-and-the-access-log)). |

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

## TLS

**HTTPS is required for push notifications**, the offline copy and the install offer: a
service worker registers only on a secure origin.

Order matters when you switch HTTPS on. Issue the certificate and check that
`https://<domain>/` answers first, then enable the HTTP to HTTPS redirect, then HSTS
(`Strict-Transport-Security: max-age=31536000` — a browser remembers it for a year, there
is no going back). A redirect in front of a certificate that does not exist yet is a
redirect loop. On the FTP host both go in `www/.htaccess` ([above](#http-to-https-redirect));
in the Docker stack, in the reverse proxy.

## Content-Security-Policy

PHP sends an enforced `Content-Security-Policy` on every page it answers (on Apache and on
Docker alike), with a fresh nonce per request: a `<script>` without that nonce does
not run. `connect-src` is `'self'` by default — kissj is called by PHP, never by the
browser — and an event's `csp` key may extend it like the other directives.
Every font and the icon font are served from the app itself (`www/fonts/`,
`www/vendor/`), so `style-src` and `font-src` name no other origin; a published map's
origin is added to `frame-src` automatically. Anything else an event embeds — photos or
videos from another site — is named in its `config.php` under `csp`, as `https://`
origins without a path; an unknown directive or a malformed origin stops the event from
booting:

```php
'csp' => [
    'img-src' => ['https://photos.example'],
    'frame-src' => ['https://video.example'],
],
```

## Push notifications

1. `php bin/generate-vapid.php` and copy both keys into `.env`.
2. Set `ADMIN_TOKEN_<SLUG>` per event.
3. `www/sw.js` must stay at the docroot root; moving it into a subdirectory narrows
   its scope, and already registered visitors stay on an orphaned registration.

What the server does with subscriptions and sends:

- `POST /<slug>/push/subscribe` refuses a subscription whose key is not a point on P-256
  with a 16-byte auth secret (400 `{"error":"invalid-key"}`): such a key passes every
  shape check and then throws inside the library's encryption. It also refuses more than
  **300 new subscriptions per address and event per 10 minutes** (429
  `{"error":"too-many"}`) — room for a camp's Wi-Fi or a carrier NAT, where hundreds of
  phones share one IPv4 address at the opening; a re-send of a known subscription, which
  `push.js` does after every login and logout, never counts, and a new one counts even
  when its welcome notification is then rejected. `push.js` tells the reader about a 429
  ("Teď si notifikace zapíná moc lidí najednou, zkus to za chvíli.") and keeps the
  browser's subscription, which the next page sends again; a 400 has its own line too
  ("Prohlížeč poslal neplatné údaje, zkus notifikace zapnout znovu.").
- One unusable subscription can no longer sink a send: a row whose encryption throws is
  set aside and deleted, and the rest of the batch goes out (only when every row fails
  that way is it treated as a fault of the platform, counted as failed and reported,
  with nothing deleted). A push service's 404 or 410 deletes the row at once; any other
  failure (a refusal, a 5 s timeout) is a strike, and **5 failed sends in a row** delete
  it. A strike is counted only when the row's push service (its endpoint's host)
  delivered at least one other notification of the same send: a send where nothing
  arrived is the server's own outage (no route out, DNS, a throttled sender), and one
  service down while the others deliver is that service's outage, so neither costs any
  reader their subscription. A delivery, or new keys behind the same endpoint, start the count again; a re-sent
  subscription with the same keys keeps its strikes.
- The admin page logs a message **before** it sends it, with empty counts that are filled
  in when the send returns, so a send that dies half-way still leaves the message on News
  and in the log (where the counts then read `–`). The result line reads `Odesláno: N,
  odstraněno neplatných odběrů: N`, plus `nedoručeno: N` when some failed. Each rendered
  send form is **one-time**: its nonce is consumed right before the send, so a double tap
  or a re-send after a timeout whose first send did complete answers 409 with the typed
  text back and sends nothing; the button is disabled after the tap. An error page from
  the admin routes keeps their `Cache-Control: no-store` and `Referrer-Policy: no-referrer`.

## kissj and its cache

On `kissj`, the answers are cached in `var/cache/<slug>/`, one JSON file per entry
(`list.json`, and one `tie-<hash>.json` per participant), served for
`PROGRAM_CACHE_TTL` seconds and then refetched. Under load and through an outage:

- **Single-flight per entry.** One request holds `<entry>.lock` and fetches; another one
  that has an expired copy serves it meanwhile, and one with nothing to show waits up to
  12 s for the holder's answer instead of asking kissj itself.
- **A 60 s breaker.** A failure writes `kissj-down.json`; for 60 s after it, any request
  with an expired copy serves it without asking kissj. A missing entry still asks — the
  breaker never turns a slow page into an empty one — and a success deletes the marker.
  One participant's bad answer opens the breaker for the whole event.
- **Sentry hears of a hidden failure at most once per 5 minutes** across all requests
  (`kissj-reported.json`).
- **Only a 404 with an empty body means "unknown TIE code"** and logs the reader out. Any
  other 404 — a maintenance page, a wrong base path, an event switched to kissj before
  the endpoints exist — is an outage.

`var/cache/` stays safe to delete at any time: the next request per event asks kissj
again. The lock files are empty and accumulate, one per participant who logged in; they
cost nothing and go with the directory. Where `var/cache/` cannot be written, all three
mechanisms quietly do nothing and the provider behaves as it did without the cache, only
reporting each failed write.

## Alternative: the Docker stack

The stack is two containers: `app` (php-fpm, built from `Dockerfile`) and `web`
(nginx, `docker/nginx.conf`). nginx serves `www/` itself and hands every other
request to `www/index.php`.

**Build and deploy from one checkout.** PHP renders the templates that are baked into
the image, while nginx serves `style.css`, `app.js` and the logos from the host's
`./www` bind mount. Pulling without `--build`, or building the image from a different
checkout than the one next to `docker-compose.prod.yml`, pairs new assets with old
templates or the reverse.

1. Create `.env` next to `docker-compose.prod.yml` from `.env.example` (see above).
   It is listed in `.dockerignore`: it never enters the image, `env_file: .env` passes
   it to the container's environment at run time.
2. `APP_RELEASE=$(git rev-parse --short HEAD) docker compose -f docker-compose.prod.yml up -d --build`.
   `APP_RELEASE` names the release in Sentry; without it the image says `dev`. A value
   in `.env` wins over the build argument because `env_file` is applied at run time,
   and so does an empty one: keep `APP_RELEASE` out of `.env` (`.env.example` has it
   commented out), or Sentry shows `unknown` instead of the hash.
   **Before the first deploy of this version, back up `var/push.sqlite`** (see the `var`
   volume below): its first request migrates the schema.
3. **Never publish php-fpm's port 9000** (no `ports:` on `app`): it speaks FastCGI with no authentication, so anyone who reaches it can run PHP. Only nginx reaches it, over the compose network.
4. The stack publishes port `8080` on the loopback interface only (`127.0.0.1:8080:80`
   in the compose file): Docker's published ports bypass ufw, and a port open to the
   world would let anyone send `X-Forwarded-For` past the proxy. Put the reverse proxy
   **on the same host** in front of it; change the `8080` if something else holds it.
   Set `TRUSTED_PROXY_COUNT=1` in `.env` behind that proxy. It must send
   `X-Forwarded-For` and pass the original `Host` (`proxy_set_header Host $host;`, see
   [the TIE login](#the-tie-login-refuses-cross-site-requests)). Without the count every
   reader shares the proxy's address and the login and subscribe limits become one limit
   for everybody.
5. Open `/`, then each event's screens (`/<slug>/`, `/<slug>/programy`, ...).
   Which screens exist depends on `features` in the event's config. On a
   production event check that the programme matches reality and not the demo
   fixtures (see `PROGRAM_PROVIDER_<SLUG>` above).

What the stack does, and why:

- **It comes back by itself.** Both containers are `restart: unless-stopped`. `web` has
  a healthcheck on `/health` every 30 s, which goes through php-fpm to the push
  database and so covers both containers; it only **reports** (`docker ps` shows
  `unhealthy`), Docker restarts nothing because of it. Both logs are `json-file`, capped
  at 5 files of 20 MB each — an unbounded log fills the disk, and then the cache and
  SQLite writes fail too.
- **The admin token stays out of nginx's access log.** nginx logs with its own format
  that drops the query string of every request whose path contains `admin` (any case)
  or any `%`-escape, so `/<slug>/admin/notify?token=…` is logged without the token. nginx's
  error log still carries the full request on an upstream error (a 502 or 504 on an
  admin request), so **rotate `ADMIN_TOKEN_<SLUG>` after the event** here too. A reverse
  proxy in front of the stack writes its own access log: make it drop the query string
  of admin requests as well.
- **Environment reaches PHP.** php-fpm clears the environment of its workers by
  default and PHP's `variables_order` would leave `$_ENV` empty. `docker/php-fpm.conf`
  sets `clear_env = no` and `docker/php.ini` sets `variables_order = "EGPCS"`. Without
  them every variable would read as unset and the app would silently run on
  its defaults (stub programmes, empty admin token).
- **The `var` volume** (`/app/var`) holds `push.sqlite` (push subscriptions and the
  log of sent messages, which is what News shows), the compiled Twig cache,
  `sessions/` and `cache/<slug>/` (see [kissj](#kissj-and-its-cache)). The entrypoint
  leaves `cache/` alone on purpose, so a kissj outage right after a redeploy still has
  data to fall back on. Back up `push.sqlite`; the subscriptions and the message log
  are the only durable state, sessions can be lost at the price of a re-login.
  The database runs in WAL mode, so `push.sqlite-wal` and `push.sqlite-shm` sit next to
  it: a backup copies all three, or runs `sqlite3 push.sqlite ".backup /path/push-backup.sqlite"`.
  `PUSH_DB_PATH` may be relative: it is resolved against the project root, so
  `var/push.sqlite` is `/app/var/push.sqlite` whatever php-fpm's working directory is.
- **Sessions survive a restart.** `App\Session` keeps them in `/app/var/sessions`, inside
  the volume, with the same settings as on the FTP host ([Sessions](#sessions));
  `docker/php.ini` configures nothing session-related. The entrypoint creates the
  directory owned by www-data, 0700, on every start, because the volume may be fresh and
  root-owned.
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
  The app's outbound calls carry their own timeouts (10 s to kissj, 5 s per push
  request), so a request normally ends well before that; one that does not — a push send
  to a large audience can — is answered with nginx's 504 while PHP finishes. PHP's
  `max_execution_time` (30 s) counts CPU time only and does not guard against a slow
  network; when it does strike it is a fatal error, not the app's error page.
  `docker/php.ini` sets `display_errors = Off`, `log_errors = On` and `expose_php = Off`,
  so a fatal error never reaches the reader: it goes to `docker logs` and, when
  configured, Sentry.
- **nginx serves `.webmanifest` as `application/manifest+json`** (`types` block in
  `docker/nginx.conf`); its `mime.types` does not know the extension.
- **nginx mirrors `www/.htaccess`**: versioned assets get a year of `immutable` cache,
  `sw.js` is `no-cache`, static files carry the same security headers PHP sets on
  everything it answers, directories are never listed, `*.php` other than the front
  controller is a 404, and text types are gzipped.
- TLS: nginx speaks plain HTTP. Terminate TLS in the reverse proxy and have it send
  `X-Forwarded-Proto: https`; nginx turns that into `HTTPS=on` for PHP, which is what
  makes the session cookie `Secure`.

## Behaviour common to both hosts

- **`GET /health`** answers `200 {"ok":true}` when the push database opens and
  `503 {"ok":false}` when it does not, with `Cache-Control: no-store`. Point an uptime
  probe at it; it migrates nothing, logs nothing and is not reported to Sentry.
- **What reaches Sentry.** An issue for every exception the error handler sees (not a
  404 or 405), for a kissj failure hidden behind a stale cache entry (at most one per 5
  minutes), for every app log line at warning or above (such as `push.failed`, one per
  send in which some notifications were refused or failed), for every throwable the push
  sender swallows, for a session store that fell back, and for PHP warnings and
  deprecations (the SDK's default integrations stay on, as in kissj). A log record that
  carries `['exception' => $e]` is **not** filed with its exception by
  `LogToSentryIssueHandler`, so code reports an exception through
  `Telemetry\Collector::collect()`, never through the logger. Routine log lines such as
  `push.sent` stay on stdout. Push and cache counts are span data (`push.send`,
  `push.welcome`, `push.subscribe`, `push.unsubscribe`, `program.cache`), not issues. A
  request that matched no route is the one transaction `unmatched`.
- **Assets carry a content hash.** `templates/_layout.twig` links the stylesheet and
  every script through `asset_version()`, which appends `?v=` and the first 8 hex
  characters of the file's sha256 (`App\Http\AssetVersion`, memoised per PHP process by
  path, mtime and size). A changed file gets a new URL on its own; there is nothing to
  bump. Event logos and favicons are not versioned: one replaced in place reaches a
  returning visitor only because the service worker revalidates it past the year-long
  HTTP cache (its fetch bypasses that cache), never through the cache itself. Upload a
  replacement under a new name and change `assets` in the event's config.
- **Fonts and icons are self-hosted and never change in place.** `www/fonts/` (themix,
  skautbold, Montserrat) and `www/vendor/fontawesome-free-5.8.1/` are committed, carry no
  hash, get the same year of `immutable` (by extension: woff2, css) and are served
  cache-first by the service worker. A changed file gets a new name — a new Montserrat
  version a new `montserrat-v<N>-…` file, a new Font Awesome a new directory — with the
  references in `www/style.css`, `templates/_layout.twig` and `App\Http\Fonts` changed to
  match. No page loads anything typographic from a CDN.
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

## Tests and CI

`.github/workflows/ci.yml` runs `composer test` (all of PHPUnit, the `browser` suite
included) on the PHP version in `.github/php-version` (8.5) on `ubuntu-latest`:
`symfony/panther` drives the runner's own Chrome through its `chromedriver`
(`PANTHER_CHROME_DRIVER_BINARY`, found with `command -v chromedriver`, falling back to
`$CHROMEWEBDRIVER/chromedriver`), and `PANTHER_NO_SKIP=1` makes a missing Chrome a
failure instead of a skip. Its `audit` job runs `composer audit --locked`. Locally the
fast lane runs on both the lowest supported PHP and the production one:
`docker run --rm -v "$PWD":/app -w /app php:8.3-alpine vendor/bin/phpunit --exclude-group browser`
and the same with `php:8.5-alpine`. The browser tests run against
`selenium/standalone-chrome`:
`docker compose --profile browser run --rm --use-aliases test vendor/bin/phpunit --group browser`
(`docker compose --profile browser stop chrome` afterwards). The `test` service runs as
root, as `slim` does, so a browser run leaves root-owned files under `var/`; if a later
run as your own user cannot write there, remove them from a container:
`docker run --rm -v "$PWD":/app -w /app php:8.3-alpine sh -c 'rm -rf var/twig/* var/sessions var/cache'`.
There is no Node anywhere in the project. Composer under the `php:8.3-alpine` image
needs `--ignore-platform-req=ext-gmp --ignore-platform-req=ext-zip` (php-webdriver
declares `ext-zip`, which Chrome never needs); the production installs are `--no-dev`
and are unaffected.

## Adding an event

1. Copy `events/obrok27/` to `events/<slug>/` and edit `config.php` and `content/`.
   The slug matches `/^[a-z0-9-]+$/` and ends in a two-digit year (`korbo26`).
2. Set `listed` (shown in the picker or not; a missing key means unlisted) and `dates`
   (`start`, `end` as `YYYY-MM-DD`; the picker splits upcoming from past on `end`) in the
   config.
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
4. Add the per-event variables above to `.env` on the host.
5. Push to `master` (the FTP pipeline deploys it), or on the Docker stack rebuild:
   `APP_RELEASE=$(git rev-parse --short HEAD) docker compose -f docker-compose.prod.yml up -d --build`.
   The new directory is found on the next request; there is no registry to edit.

To fill the fixtures from kissj's responses use `php bin/kissj-fixtures.php <slug>`.
