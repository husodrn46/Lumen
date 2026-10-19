#!/bin/sh
# Konteyner açılışı: bağımlılıklar yoksa kur, yazılabilir klasörleri hazırla.
set -e
cd /var/www/html
if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --no-progress --prefer-dist
fi
mkdir -p logs tmp database
chown -R www-data:www-data logs tmp database cek_ekleri gorev_ekleri 2>/dev/null || true
exec docker-php-entrypoint "$@"
