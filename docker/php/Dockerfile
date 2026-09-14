FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        libzip-dev \
        libpng-dev \
        libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring zip bcmath pcntl gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-interaction --optimize-autoloader \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]
