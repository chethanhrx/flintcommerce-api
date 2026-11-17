FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    oniguruma-dev \
    libxml2-dev \
    sqlite-dev \
    postgresql-dev \
    mysql-dev \
    && docker-php-ext-install pdo pdo_sqlite pdo_mysql pdo_pgsql opcache

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Setup nginx
RUN rm -f /etc/nginx/conf.d/default.conf
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf

# Setup supervisor
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Setup PHP configuration
COPY docker/php.ini /usr/local/etc/php/conf.d/99-production.ini

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage \
    && chmod -R 775 /var/www/html/storage

# Expose port
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

# Run supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]