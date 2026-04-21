FROM php:8.2-apache

RUN apt-get update && apt-get install -y unzip libssl-dev && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo pdo_mysql \
    && pecl install redis && docker-php-ext-enable redis \
    && a2enmod rewrite

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --ignore-platform-reqs --no-interaction

COPY . .

RUN mkdir -p /var/www/html/storage/app/otp_sessions \
    && chmod -R 777 /var/www/html/storage \
    && printf '<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
       > /etc/apache2/conf-available/app.conf \
    && a2enconf app

EXPOSE 80
