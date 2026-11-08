FROM php:8.2-cli

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        rsync \
        openssh-client \
        git \
        unzip \
        curl \
        tar \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_sqlite

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /work
