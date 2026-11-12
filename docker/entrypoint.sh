#!/bin/sh
set -e

# Render fournit le port à écouter dans $PORT (10000 par défaut).
PORT="${PORT:-80}"
sed -i "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Lien public pour les anciens fichiers (les photos sont désormais en base).
php artisan storage:link >/dev/null 2>&1 || true

# Base de données : structure puis données du marché (une seule fois).
php artisan migrate --force
php artisan marketplace:import --if-empty

# Cache de production.
php artisan config:cache
php artisan route:cache || true
php artisan view:cache || true

exec apache2-foreground
