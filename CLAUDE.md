# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Modular support web app for Czech scout events (Obrok). Built on Slim 4 (PHP >= 8.3). One codebase serves multiple events — each event is a config + content directory under `events/<slug>/` plus public assets under `www/events/<slug>/`, selected at runtime via the `EVENT` env var. UI text, comments, and commit messages in the codebase (templates, per-event content) are in Czech; commit messages for this repo's own history are in English without AI-authorship trailers.

## Commands

- `docker-compose up` — run the dev server (PHP built-in server inside `php:8.3-alpine`), then visit `localhost:8080`
- `composer start` — alternative: run locally via `php -S localhost:8080 -t www/`
- `composer test` / `vendor/bin/phpunit` — run tests (PHPUnit 11, config in `phpunit.xml`, suites `Unit` + `Functional`)
- `vendor/bin/phpunit --filter testGetHomepage` — run a single test

Before running, copy `.env.example` to `.env` and set `EVENT=obrok19` (the reference event) and `APP_DEBUG=1` for dev.

## Architecture

`www/index.php` is a 3-line front controller: it requires the Composer autoloader and calls `App\Kernel::create()->run()`. All bootstrap logic lives in `src/Kernel.php` — no `settings.php`/`dependencies.php`/`middleware.php`/`routes.php` split, no route closures scattered across a routes file.

`Kernel::create()`:
- Loads `.env` (if present) and reads the `EVENT` env var to pick which event to serve; loads `events/<slug>/config.php` via `EventConfig::load()`.
- Builds a PHP-DI container (`ContainerBuilder`) with the event, the program provider, `Session`, `Auth\Authenticator`, `Auth\SkautisGatewayInterface`, and Twig as definitions.
- Creates the Slim 4 `App`, adds body-parsing/routing middleware, Twig middleware, and error middleware (`APP_DEBUG` controls whether error details are shown).
- Registers core routes (`GET /` homepage, `POST /` SkautIS login/logout) directly, then iterates `$event->features` and registers one module per enabled feature.

`EventConfig` (`src/EventConfig.php`) loads and validates `events/<slug>/config.php` (requires `name`, `colors`, `features`; also carries `sections`, arbitrary `raw` data via `get()`, and `dir` for locating fixtures/content). `EventConfig::content(string $name)` loads `events/<slug>/content/<name>.php`, a plain PHP file returning an array (e.g. `content/links.php`, `content/news.php`, `content/schedule.php`) — these are per-event, not code.

Modules implement `App\Module\ModuleInterface` (`key()`, `menuItem()`, `registerRoutes()`) and live in `src/Module/`. `ModuleRegistry::classFor($feature)` maps a feature-flag string (from the event's `features` array in its config) to a module class — this is how features are toggled per event, e.g. `NewsModule`, `LinksModule`, `MapModule`, `HandbookModule`, `ProgramsModule`, `HarmonogramModule`. `PushModule` (web push notifications) is implemented: `POST /push/subscribe` saves a subscription via `App\Push\SubscriptionRepository`, and a token-gated `GET/POST /admin/notify` (shareable link with `?token=<ADMIN_TOKEN>`) sends notifications through `App\Push\PushSenderInterface`/`WebPushSender`.

Program/schedule data goes through `App\Program\ProgramProviderInterface` (`getPrograms()`, `getProgramsForIdentity()`). `StubProgramProvider` reads `events/<slug>/fixtures/programs.json` and `fixtures/registered.json` — no network calls, safe for dev and tests. `KissjProgramProvider` (Guzzle-based) talks to the real kissj registration API. `Kernel` switches between them via `PROGRAM_PROVIDER=stub|kissj` in `.env` (default `stub`); `kissj` additionally requires `KISSJ_BASE_URL` (`Kernel` throws a `RuntimeException` otherwise) and uses the event's slug unless overridden by the per-event config key `kissj.eventSlug`. Confirm `docs/kissj-contract.md` against the real kissj API before flipping a production event to `kissj`.

SkautIS SSO lives under `src/Auth/`: `Authenticator` (session-backed login state), `SkautisGatewayInterface` + `SkautisGateway` (wraps `skautis/skautis`), `Identity` (value object for a logged-in skautis or TIE-code participant), `UnknownParticipantException`. Login happens via `POST /` with a `skautIS_Token` form field; logout via `skautIS_Logout`.

Theming: each event's `config.php` has a `colors` map (e.g. `base`, `darker`, `primary`, `text`, `text-invert`) and an `assets` map (logo/favicon paths under `www/events/<slug>/`). `Kernel` exposes the whole event config as the `event` Twig global; `_layout.twig` emits the colors as `--color-*` CSS custom properties in an inline `<style>` block, and `www/style.css` consumes them (`var(--color-base)`, etc.) — no per-event CSS files, one shared stylesheet.

Templates in `templates/` (shared by all events) all extend `_layout.twig`, which builds the menu from the registered modules and renders the theme CSS vars.

Tests: `tests/Unit/` (`EventConfigTest`, `ModuleRegistryTest`, `StubProgramProviderTest`, `KissjProgramProviderTest`, `KernelProviderSelectionTest` — asserts `PROGRAM_PROVIDER=stub|kissj` picks the right provider — `AuthenticatorTest`, `SessionTest`, `SubscriptionRepositoryTest`, `EnvironmentTest`) and `tests/Functional/` (one app instance per test via `Kernel::create()`, exercising real routes end-to-end: `HomepageTest`, `LoginTest`, `ProgramsTest`, `HarmonogramTest`, `TieLoginTest`, `NewsModuleTest`, `LinksMapTest`, `HandbookTest`, `ThemingTest`, `Obrok27Test`, `PushSubscribeTest`, `AdminNotifyTest`, `ProviderFailureTest`). `tests/Functional/AppTestCase::createApp()` boots the real app against event fixtures — either a real event under `events/<slug>/` or a minimal fixture event under `tests/fixtures/events/` — and returns the real Slim `App`/response. No test hits a live external API; everything program-related comes from the stub provider's JSON fixtures.
