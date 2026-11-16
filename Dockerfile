FROM php:8.2-apache
RUN apt-get update && apt-get install -y libssl-dev && pecl install mongodb && docker-php-ext-enable mongodb
COPY . /var/www/html/
EXPOSE 80
