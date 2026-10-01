# SAQF — PHP 8.3 + Apache. The web server serves ONLY public/; src/, data/, bin/ and database/ are never web-accessible.
FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod headers rewrite \
    && a2disconf other-vhosts-access-log >/dev/null 2>&1 || true

ENV APACHE_DOCUMENT_ROOT=/var/www/saqf/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!/var/www/saqf/public/!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-saqf.ini
COPY docker/apache-saqf.conf /etc/apache2/conf-enabled/zz-saqf.conf
COPY docker/entrypoint.sh /usr/local/bin/saqf-entrypoint

WORKDIR /var/www/saqf
COPY . /var/www/saqf
# Never ship local secrets; code is read-only for the web server user.
RUN rm -f /var/www/saqf/config.local.php /var/www/saqf/.env \
    && chmod +x /usr/local/bin/saqf-entrypoint \
    && chown -R root:root /var/www/saqf && chmod -R a+rX,go-w /var/www/saqf

ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_TIMEZONE=Asia/Riyadh

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/health.php") === false ? 1 : 0);'
ENTRYPOINT ["saqf-entrypoint"]
CMD ["apache2-foreground"]
