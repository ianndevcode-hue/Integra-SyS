FROM php:8.3-cli-alpine

# Extensões exigidas pelo NFePHP/sped-nfe:
# curl, dom, json, openssl, xml e mbstring já vêm no php:cli-alpine;
# soap e zip precisam ser compilados.
RUN apk add --no-cache libxml2-dev libzip-dev icu-dev \
    && docker-php-ext-install soap zip bcmath \
    && apk del libxml2-dev

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts || true

COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p storage/certificates storage/xml/nfe storage/xml/nfce \
       storage/xml/cancelamentos storage/xml/inutilizacoes storage/xml/cce \
       storage/danfe storage/logs

ENV FISCAL_SERVICE_PORT=3334

EXPOSE 3334

CMD ["sh", "-c", "php -S 0.0.0.0:${FISCAL_SERVICE_PORT} -t public public/index.php"]
