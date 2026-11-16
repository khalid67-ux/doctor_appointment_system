FROM php:8.2-apache

# Install required system packages, build tools, and PHP mongodb extension
RUN apt-get update && apt-get install -y \
    build-essential \
    pkg-config \
    libssl-dev \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/* \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb

# Install Composer inside container
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy project files to container web root
COPY . /var/www/html/

# Set working directory, remove old lock/vendor, and install compatible mongodb library
WORKDIR /var/www/html
RUN rm -rf vendor composer.lock \
    && composer require mongodb/mongodb --no-interaction --no-dev --optimize-autoloader

EXPOSE 80
