FROM ubuntu:24.04

ARG DEBIAN_FRONTEND=noninteractive
ARG PHP_VERSION=8.2
ARG UID=1000
ARG GID=1000

ENV LANG=C.UTF-8
ENV LC_ALL=C.UTF-8
ENV COMPOSER_HOME=/var/www/.composer

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl gnupg software-properties-common \
        supervisor nginx make unzip git \
    && apt-get clean

RUN add-apt-repository -y ppa:ondrej/php \
    && curl -sL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get update && apt-get install -y --no-install-recommends \
        php${PHP_VERSION}-cli \
        php${PHP_VERSION}-fpm \
        php${PHP_VERSION}-common \
        php${PHP_VERSION}-mysql \
        php${PHP_VERSION}-xml \
        php${PHP_VERSION}-curl \
        php${PHP_VERSION}-mbstring \
        php${PHP_VERSION}-zip \
        php${PHP_VERSION}-gd \
        php${PHP_VERSION}-intl \
        php${PHP_VERSION}-opcache \
        php${PHP_VERSION}-bcmath \
        php${PHP_VERSION}-calendar \
        php${PHP_VERSION}-exif \
        php${PHP_VERSION}-apcu \
        nodejs \
    && npm install -g yarn && npm cache clean --force \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename composer

# Everything that writes into the bind mount runs as the host user, so the files
# it creates are owned by whoever runs Docker rather than by root. Ubuntu 24.04
# ships a default `ubuntu` account on 1000:1000, which occupies the usual ids.
RUN userdel -r ubuntu >/dev/null 2>&1 || true; \
    groupdel ubuntu >/dev/null 2>&1 || true; \
    groupmod -o -g ${GID} www-data \
    && usermod -o -u ${UID} -g ${GID} -d /var/www -s /bin/bash www-data \
    && mkdir -p /var/www/.composer /var/www/.cache /run/php \
    && chown -R www-data:www-data /var/www /run/php

RUN ln -s /usr/sbin/php-fpm${PHP_VERSION} /usr/sbin/php-fpm \
    && git config --system --add safe.directory /app

COPY .docker/supervisord.conf     /etc/supervisor/conf.d/supervisor.conf
COPY .docker/nginx.conf           /etc/nginx/nginx.conf
COPY .docker/fpm.conf             /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
COPY .docker/php.ini              /etc/php/${PHP_VERSION}/fpm/conf.d/99-plugin.ini
COPY .docker/php.ini              /etc/php/${PHP_VERSION}/cli/conf.d/99-plugin.ini
COPY .docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

RUN chmod +x /usr/local/bin/docker-entrypoint

WORKDIR /app

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisor.conf"]
