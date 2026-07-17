FROM php:8.2-apache

# Enable Apache mod_rewrite (not strictly required here, but harmless/useful)
RUN a2enmod rewrite

# Install the MySQL PDO driver the app relies on
RUN docker-php-ext-install pdo pdo_mysql

# Apache should serve the "lasu" folder directly as the web root
COPY lasu/ /var/www/html/

# Apache's default port
EXPOSE 80

# Make sure PHP errors go to the container log, not the browser
RUN { \
    echo 'display_errors = Off'; \
    echo 'log_errors = On'; \
    echo 'error_log = /dev/stderr'; \
  } > /usr/local/etc/php/conf.d/logging.ini
