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

RUN printf "output_buffering=4096\nupload_max_filesize=5M\npost_max_size=8M\n" > /usr/local/etc/php/conf.d/custom.ini

RUN mkdir -p /var/www/html/uploads/doctors \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chmod -R 775 /var/www/html/uploads

EXPOSE 80
