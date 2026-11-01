FROM php:8.2-apache

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions pdo_sqlite pdo_mysql opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/www/html/*

COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-shared.ini

WORKDIR /var/www/html
