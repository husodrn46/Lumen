# Lumen geliştirme/demo uygulama imajı: PHP 8.2 (desteklenen taban) php-fpm + pdo_sqlsrv.
# (mod_php + msodbcsql18, Apple Silicon'da amd64 emülasyonu altında segfault veriyor;
#  php-fpm sorunsuz. Önünde nginx konteyneri çalışır.)
# Microsoft ODBC sürücüsü yalnız amd64 için paketlendiğinden imaj amd64 derlenir
# (Apple Silicon'da Rosetta/QEMU ile çalışır).
FROM php:8.2-fpm-bookworm

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends curl gnupg2 unixodbc-dev libpng-dev libjpeg62-turbo-dev \
        libfreetype6-dev libwebp-dev libzip-dev unzip git; \
    curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft.gpg; \
    echo "deb [arch=amd64 signed-by=/usr/share/keyrings/microsoft.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" \
        > /etc/apt/sources.list.d/mssql-release.list; \
    apt-get update; \
    ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 mssql-tools18; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" gd zip; \
    pecl install sqlsrv-5.12.0 pdo_sqlsrv-5.12.0; \
    docker-php-ext-enable sqlsrv pdo_sqlsrv; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
ENV PATH="/opt/mssql-tools18/bin:${PATH}"

RUN { \
      echo 'display_errors=Off'; \
      echo 'log_errors=On'; \
      echo 'error_log=/var/www/html/logs/php/php_errors.log'; \
      echo 'variables_order=EGPCS'; \
      echo 'error_reporting=E_ALL'; \
      echo 'upload_max_filesize=12M'; \
      echo 'post_max_size=16M'; \
      echo 'date.timezone=Europe/Istanbul'; \
    } > /usr/local/etc/php/conf.d/lumen.ini

COPY giris.sh /usr/local/bin/lumen-giris
RUN chmod +x /usr/local/bin/lumen-giris
ENTRYPOINT ["lumen-giris"]
CMD ["php-fpm"]

# php-fpm, konteyner ortam değişkenlerini (AKL_DB_* vb.) PHP'ye aktarsın.
RUN sed -i 's/^;clear_env = no/clear_env = no/' /usr/local/etc/php-fpm.d/www.conf
