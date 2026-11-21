# BEACON on Render (or any Docker host).
# PHP 8.2 + Apache, with pdo_pgsql for Supabase. sodium is built into this image.
FROM php:8.2-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install pdo_pgsql pgsql \
 && apt-get purge -y --auto-remove \
 && rm -rf /var/lib/apt/lists/*

# Production PHP settings plus BEACON's own (timezone, no error output to visitors).
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/beacon.ini"

# Apache: listen on Render's $PORT, trust its proxy for visitor IPs, block private folders.
COPY docker/apache.conf /etc/apache2/conf-available/beacon.conf
RUN a2enmod remoteip headers \
 && a2enconf beacon \
 && sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
 && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf

ENV PORT=10000
EXPOSE 10000

COPY . /var/www/html/
# Never ship local secrets or the setup checker, even if they slip into the build.
RUN rm -f /var/www/html/config/config.php /var/www/html/config/supabase.php /var/www/html/setup_check.php \
 && chown -R www-data:www-data /var/www/html
