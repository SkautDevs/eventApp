# HTTP

This document covers the middleware stack around every route, the Content-Security-Policy and how errors are rendered.

## The middleware stack

The middleware stack, innermost to outermost: body parsing, routing (with `Telemetry\RouteNameMiddleware` added before it, i.e. inside it, so the route is known), Twig, error, session cookie (`Http\SessionCookieMiddleware`), screen (`Http\ScreenMiddleware`), security headers (`Http\SecurityHeadersMiddleware`), `Http\CspMiddleware`, and `Telemetry\TransactionMiddleware` last — outermost, so the error middleware's 500 is measured. What each of the three middle ones does (the sliding login cookie; fragment mode, `Vary: X-Screen` and the pages' `Cache-Control`; the four fixed headers) is in its docblock. `createInstance()` has the same order without the Twig, session-cookie and screen layers (its route naming is `RouteNameMiddleware(null)`).

## Content-Security-Policy

The Content-Security-Policy is enforced by `Http\CspMiddleware`: `script-src 'self' 'nonce-…'` with a fresh nonce per request in the Twig global `cspNonce`, so every inline `<script>` carries `nonce="{{ cspNonce }}"` and no element may carry an `on*=` handler (the push button is `data-push-toggle`, bound in `www/js/push/index.js`). Styles stay `'unsafe-inline'`: the timeline's per-programme `style` attributes cannot be hashed and must lay the screen out without JS, and an inline style can neither run code nor read data. Fonts and icons are `'self'` (self-hosted, above), so `style-src` is `'self' 'unsafe-inline'` and `font-src` `'self'`; the event's map origin is added automatically; anything else goes in the optional config key `csp` (`EventConfig::$csp`, `['frame-src' => ['https://…']]`, validated at load). `CspTest` renders every participating route and fails on a missing nonce.

## Error pages

Errors render through `App\Http\TwigErrorRenderer`, registered for `text/html` and as the default on `QuietErrorHandler`: the event's `templates/error.twig` extends the layout — 404 and 405 read "Tohle tady není.", anything else "Něco se pokazilo. Zkus to za chvíli.", both with a link home, and under `APP_DEBUG` a `<details class="error-details">` with Slim's detail set; the instance app uses the plain `templates/instance-error.twig`. The same `error.twig` renders the app's 403 refusals with a `message` and a `back` link of their own (a cross-site pairing start or logout, a cross-site or unpaired register). A fragment request that errors gets the whole document. An error page that itself fails to render falls back to `TwigErrorRenderer::FALLBACK` and is reported through `Collector`; JSON clients keep Slim's JSON.
