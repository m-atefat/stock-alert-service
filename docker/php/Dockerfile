FROM php:8.4-fpm-bookworm

ARG USER=appuser
ARG GROUP=appuser
ARG UID=1000
ARG GID=1000

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libssl-dev \
        supervisor \
    && docker-php-ext-install pdo_pgsql pgsql pcntl bcmath sockets zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN groupadd -g ${GID} ${GROUP} \
    && useradd -u ${UID} -g ${GROUP} -m -s /bin/bash ${USER}

COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /var/www/html

RUN chown -R ${USER}:${GROUP} /var/www/html

USER ${USER}

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
