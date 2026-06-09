FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev libzip-dev \
    zip unzip procps && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# phpredis (the "Redis" class) is a PECL extension, not bundled — build it via pecl.
RUN apt-get update && apt-get install -y --no-install-recommends $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
