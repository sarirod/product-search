# PHP 8.3 + Apache. Only the public/ folder is web-accessible; src/, bin/, data/ are not.
FROM php:8.3-apache

# Postgres driver for PDO (curl, json and mbstring are already part of the base image).
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

# Serve public/ as the document root.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && printf '%s\n' 'ServerName localhost' 'ServerTokens Prod' 'ServerSignature Off' > /etc/apache2/conf-enabled/hardening.conf \
 && printf '%s\n' 'expose_php = Off' 'display_errors = Off' 'log_errors = On' > /usr/local/etc/php/conf.d/production.ini

COPY . /var/www/html/

# Render (and most hosts) tell the container which port to listen on via $PORT.
CMD ["sh", "-c", "sed -i \"s/^Listen 80$/Listen ${PORT:-80}/\" /etc/apache2/ports.conf && sed -i \"s/<VirtualHost \\*:80>/<VirtualHost *:${PORT:-80}>/\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]
