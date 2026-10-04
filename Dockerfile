FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader --ignore-platform-req=ext-gmp

FROM php:8.3-fpm-alpine
RUN apk add --no-cache gmp \
 && apk add --no-cache --virtual .build gmp-dev $PHPIZE_DEPS \
 && docker-php-ext-install opcache gmp \
 && apk del .build
COPY docker/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY src ./src
COPY templates ./templates
COPY events ./events
COPY www ./www
RUN chmod +x /usr/local/bin/app-entrypoint \
 && mkdir -p var && chown www-data:www-data var
VOLUME /app/var
# The release Sentry files events under; docker-compose.prod.yml passes the git hash.
# A value in .env still wins, because env_file is applied at run time. Declared last, so
# a new hash per deploy does not invalidate the extension build layers above.
ARG APP_RELEASE=dev
ENV APP_RELEASE=$APP_RELEASE
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]
