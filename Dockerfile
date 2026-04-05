FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-interaction --no-scripts --optimize-autoloader
COPY . .
RUN composer install --prefer-dist --no-interaction --no-scripts --optimize-autoloader

FROM node:20-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY public ./public
COPY vite.config.js postcss.config.js .
RUN npm run build

FROM php:8.4-cli-alpine AS app
WORKDIR /var/www/html

RUN apk add --no-cache \
    $PHPIZE_DEPS \
    bash \
    icu-dev \
    libzip-dev \
    python3 \
    py3-pip \
    tesseract-ocr \
    tesseract-ocr-data-eng \
    tesseract-ocr-data-spa \
    poppler-utils \
    oniguruma-dev \
    zip \
    unzip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pdo_mysql bcmath intl pcntl zip \
    && pip3 install --no-cache-dir --break-system-packages pypdf pdf2image pytesseract

COPY --from=vendor /app /var/www/html
COPY --from=frontend /app/public/build /var/www/html/public/build
COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && mkdir -p /var/www/html/storage/framework/cache/data \
                /var/www/html/storage/framework/sessions \
                /var/www/html/storage/framework/views \
                /var/www/html/storage/logs \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
