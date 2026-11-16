FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    build-essential \
    pkg-config \
    libssl-dev \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/* \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY . /var/www/html/

WORKDIR /var/www/html
RUN rm -rf vendor composer.lock \
    && composer require "mongodb/mongodb:^2.0" --no-interaction --optimize-autoloader

    RUN echo "output_buffering=4096" > /usr/local/etc/php/conf.d/custom.ini

EXPOSE 80
