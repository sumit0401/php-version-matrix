FROM php:8.1-apache

RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libxml2-dev \
    libicu-dev libcurl4-openssl-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_mysql mysqli zip xml dom intl soap mbstring curl opcache

RUN echo "memory_limit=256M" > /usr/local/etc/php/conf.d/qloapps.ini \
    && echo "upload_max_filesize=32M" >> /usr/local/etc/php/conf.d/qloapps.ini \
    && echo "post_max_size=32M" >> /usr/local/etc/php/conf.d/qloapps.ini \
    && echo "max_execution_time=500" >> /usr/local/etc/php/conf.d/qloapps.ini \
    && echo "allow_url_fopen=on" >> /usr/local/etc/php/conf.d/qloapps.ini

RUN a2enmod rewrite
