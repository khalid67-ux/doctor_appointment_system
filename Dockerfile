FROM php:8.2-apache

# Install required system packages, build tools, and PHP mongodb extension
RUN apt-get update && apt-get install -y \
    build-essential \
    pkg-config \
    libssl-dev \
    git \
    unzip \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb

# Install Composer inside container
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy project files to container web root
COPY . /var/www/html/

# Set working directory and install composer packages freshly
WORKDIR /var/www/html
RUN composer install --no-dev --optimize-autoloader

EXPOSE 80
