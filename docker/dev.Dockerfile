# The dev server's image (docker-compose.yml, service `slim`): PHP's CLI with Composer,
# so `docker exec -u 1000 -it eventapp-slim-1 sh` has `composer` ready. gmp and zip are
# the two extensions the locked packages ask for that the stock image lacks.
FROM php:8.5-alpine
RUN apk add --no-cache git unzip gmp libzip \
 && apk add --no-cache --virtual .build gmp-dev libzip-dev $PHPIZE_DEPS \
 && docker-php-ext-install gmp zip \
 && apk del .build
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
# any uid may run it: the cache and config live outside a home directory
ENV COMPOSER_HOME=/tmp/composer
