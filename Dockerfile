FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers

COPY . /var/www/html/

RUN rm -f /var/www/html/config.local.php \
    && chown -R www-data:www-data /var/www/html

ENV APP_ENV=production
ENV APP_DEBUG=false

EXPOSE 80
