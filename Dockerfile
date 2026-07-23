FROM wordpress:7.0-php8.3-apache

RUN apt-get update && \
	apt-get install -y  --no-install-recommends ssl-cert less mariadb-client && \
	rm -r /var/lib/apt/lists/* && \
	a2enmod ssl rewrite expires && \
	a2ensite default-ssl

# WP-CLI, for `docker compose exec wordpress wp ...` during development.
# `less` and the mariadb client above let `wp db ...` and paged output work.
RUN curl -sS -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && \
	chmod +x /usr/local/bin/wp && \
	printf '#!/bin/sh\nexec wp --allow-root "$@"\n' > /usr/local/bin/wpc && \
	chmod +x /usr/local/bin/wpc

ENV PHP_INI_PATH="/usr/local/etc/php/php.ini"

RUN pecl install xdebug-3.5.1 && docker-php-ext-enable xdebug \
    && echo "xdebug.mode=debug" >> ${PHP_INI_PATH} \
    && echo "xdebug.client_port=9003" >> ${PHP_INI_PATH} \
    && echo "xdebug.client_host=host.docker.internal" >> ${PHP_INI_PATH} \
    && echo "xdebug.start_with_request=yes" >> ${PHP_INI_PATH} \
    && echo "xdebug.log=/tmp/xdebug.log" >> ${PHP_INI_PATH} \
    && echo "xdebug.idekey=IDE_DEBUG" >> ${PHP_INI_PATH}

EXPOSE 80
EXPOSE 443
