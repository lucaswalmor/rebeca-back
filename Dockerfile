FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    nginx \
    curl \
    zip \
    unzip \
    git \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    libzip-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring exif pcntl bcmath gd zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ✅ Configurações PHP para upload grande
RUN echo "upload_max_filesize=2048M" > /usr/local/etc/php/conf.d/uploads.ini \
 && echo "post_max_size=2100M" >> /usr/local/etc/php/conf.d/uploads.ini \
 && echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/uploads.ini \
 && echo "max_execution_time=600" >> /usr/local/etc/php/conf.d/uploads.ini \
 && echo "max_input_time=600" >> /usr/local/etc/php/conf.d/uploads.ini

RUN mkdir -p /var/log/nginx

WORKDIR /var/www
COPY . .

RUN composer install --no-dev --optimize-autoloader

RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

COPY docker/nginx.conf /etc/nginx/sites-available/default

EXPOSE 80
CMD php artisan config:clear && \
    php artisan key:generate --force && \
    php artisan migrate --force && \
    php-fpm -D && \
    nginx -g "daemon off;"