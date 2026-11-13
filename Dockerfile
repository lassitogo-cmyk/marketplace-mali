FROM php:8.4-apache

# Réglages PHP de production (expose_php Off, limites d'upload, erreurs masquées).
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-marketplace.ini

# Serveur discret : pas de version dans les en-têtes.
RUN echo "ServerTokens Prod" > /etc/apache2/conf-available/zz-discret.conf \
    && echo "ServerSignature Off" >> /etc/apache2/conf-available/zz-discret.conf \
    && echo "TraceEnable Off" >> /etc/apache2/conf-available/zz-discret.conf \
    && a2enconf zz-discret

# Extensions PHP nécessaires (PostgreSQL en ligne, zip, texte, cache).
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libzip-dev libonig-dev libxml2-dev zip unzip git curl \
    && docker-php-ext-install pdo pdo_pgsql pgsql mbstring zip bcmath opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Le code de l'application (le dossier api/ est la racine du dépôt).
COPY . .

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache

# Apache sert directement le dossier public/ (marche.html + API Laravel).
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
