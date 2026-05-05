FROM php:8.4-apache

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite \
    && printf '<Directory /var/www/html/public_html>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/nene2.conf \
    && a2enconf nene2 \
    && sed -ri -e 's!/var/www/html!/var/www/html/public_html!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!/var/www/html/public_html!g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
