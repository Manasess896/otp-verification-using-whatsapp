FROM php:8.2.35-apache


RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libcurl4-openssl-dev \
    pkg-config \
    libssl-dev \
    && docker-php-ext-install zip \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-interaction --optimize-autoloader --no-dev --no-scripts
COPY . .
RUN composer dump-autoload --optimize
RUN chown -R www-data:www-data /var/www/html
EXPOSE 80

CMD ["sh", "-c", "PORT=\"${PORT:-80}\"; sed -i \"s/Listen 80/Listen ${PORT}/g\" /etc/apache2/ports.conf; sed -i \"s#<VirtualHost \\*:80>#<VirtualHost *:${PORT}>#g\" /etc/apache2/sites-available/000-default.conf; exec apache2-foreground"]