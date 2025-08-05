FROM php:8.2-fpm-alpine

# Instalar dependencias del sistema necesarias para extensiones PHP
# libpq-dev es para pdo_pgsql y pgsql
RUN apk add --no-cache libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev postgresql-dev icu-dev oniguruma-dev supervisor

# Instalar extensiones PHP
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    pdo pdo_mysql pdo_pgsql pgsql zip intl exif bcmath opcache

# Instalar Composer globalmente
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Establecer el directorio de trabajo
WORKDIR /var/www/html

# Copiar el código de la aplicación
COPY . .

# Instalar dependencias de Composer (haz esto como un paso separado para aprovechar el caché de Docker)
# Primero copia solo composer.json y composer.lock
# COPY composer.json composer.lock ./
# RUN composer install --no-dev --no-interaction --no-plugins --no-scripts --prefer-dist
# COPY . .
# Descomenta las 3 líneas anteriores y comenta la línea `COPY . .` de arriba si quieres optimizar la cache de build.
# Por ahora, para simplicidad, copiamos todo y luego instalamos.

RUN composer install --optimize-autoloader --no-dev --no-interaction --no-progress

# Ajustar permisos
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
RUN chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Exponer el puerto 9000 para PHP-FPM
EXPOSE 9000

# Comando por defecto (puede ser sobrescrito o no usado si solo usas para CLI)
CMD ["php-fpm"]