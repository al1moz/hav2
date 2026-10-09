FROM php:7.4-fpm

RUN docker-php-ext-install pdo_mysql \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && { \
      echo 'expose_php = Off'; \
      echo 'date.timezone = UTC'; \
      echo 'memory_limit = 256M'; \
    } > "$PHP_INI_DIR/conf.d/consov2.ini" \
 && echo 'clear_env = no' >> /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/consov2
