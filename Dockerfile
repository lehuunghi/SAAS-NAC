FROM node:22-alpine AS assets
WORKDIR /build
COPY package*.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

FROM php:8.4-fpm-bookworm AS app
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libonig-dev libzip-dev unzip git openssh-client sshpass python3 python3-venv \
    && docker-php-ext-install pdo_mysql mbstring intl zip pcntl opcache \
    && python3 -m venv /opt/ansible \
    && /opt/ansible/bin/pip install --no-cache-dir ansible-core==2.19.3 \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
COPY --from=assets /build/public/build ./public/build
RUN chown -R www-data:www-data storage bootstrap/cache
USER www-data
CMD ["php-fpm"]

FROM nginx:1.28-alpine AS web
COPY --from=assets /build/public/build /var/www/html/public/build
COPY infrastructure/nginx/default.conf /etc/nginx/conf.d/default.conf
