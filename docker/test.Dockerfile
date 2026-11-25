# The browser tests' container (the compose `test` service): php:8.5-alpine, the lowest
# supported PHP, plus libfaketime, which BrowserTestCase::freezeServerClock() preloads into
# the PHP server it starts, for a test that needs the app inside an event's days.
FROM php:8.5-alpine

RUN apk add --no-cache libfaketime
