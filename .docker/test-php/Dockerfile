FROM php:8.4-cli

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN install-php-extensions intl zip gd exif pdo_mysql @composer \
    && apt-get update && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && echo 'memory_limit = 1G' > /usr/local/etc/php/conf.d/zz-memory-limit.ini

WORKDIR /app