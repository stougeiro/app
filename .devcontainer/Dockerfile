FROM php:8.2-cli

# Opcional: instalar extensões, ferramentas, etc.
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# pcov: driver de cobertura leve para os testes (pest --coverage)
RUN pecl install pcov \
    && docker-php-ext-enable pcov

# Instalar Composer manualmente (caso não use feature)
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm composer-setup.php

WORKDIR /app
