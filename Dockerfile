FROM php:8.4-fpm-alpine

# Set working directory
WORKDIR /var/www

# Install system dependencies, build libraries, and shadow package for user mapping
RUN apk update && apk add --no-cache \
    curl \
    git \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    oniguruma-dev \
    autoconf \
    g++ \
    make \
    openssl-dev \
    supervisor \
    shadow

# Modify www-data user to have UID/GID 1000
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip opcache

# Install MongoDB and Redis PHP extensions via PECL
RUN pecl install mongodb redis && docker-php-ext-enable mongodb redis

# Copy production OPcache configuration
COPY docker/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy existing application directory contents
COPY . /var/www

# Install Laravel dependencies for production
RUN composer install --no-interaction --optimize-autoloader --no-dev --ignore-platform-reqs

# Prepare writable paths and assign permissions to www-data
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache \
    && chmod -R 775 /var/www/storage /var/www/bootstrap/cache \
    && mkdir -p /var/run /usr/local/var/log /var/log/supervisor \
    && chown -R www-data:www-data /var/run /usr/local/var/log /var/log/supervisor

# Expose port 9000 for PHP-FPM
EXPOSE 9000

# Set non-root execution context
USER www-data

# Start Supervisor to manage PHP-FPM, Horizon, and Scheduler
CMD ["/usr/bin/supervisord", "-c", "/var/www/docker/supervisor.conf"]
