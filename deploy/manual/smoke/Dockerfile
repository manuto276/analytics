# syntax=docker/dockerfile:1
# Imitates a managed PHP host (CloudPanel-like) for the deploy smoke test:
# - php:   PHP-FPM 8.4 running entirely as the unprivileged site user, OPcache with
#          validate_timestamps=0 (releases must be picked up through $realpath_root)
# - web:   nginx whose workers can read the site files only through the site group
# The deploy root is a shared volume mounted at the same path in both containers.

ARG DEPLOY_ROOT=/home/site/htdocs/stats.example.test

FROM php:8.4-fpm AS php
ARG DEPLOY_ROOT
RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev default-mysql-client ca-certificates \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql intl opcache \
 && rm -rf /var/lib/apt/lists/*
RUN groupadd -g 1000 site \
 && useradd -m -u 1000 -g site -s /bin/bash site \
 && mkdir -p "${DEPLOY_ROOT}" \
 && chown -R site:site /home/site \
 && chmod 0750 /home/site /home/site/htdocs "${DEPLOY_ROOT}"
# MariaDB's mysqldump verifies the server certificate by default; MySQL 8.4's is self-signed here.
RUN printf '[client]\ndisable-ssl-verify-server-cert\n' > /etc/mysql/conf.d/zz-smoke-client.cnf
COPY php-host.ini /usr/local/etc/php/conf.d/zz-managed-host.ini
COPY php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-site.conf
USER site
WORKDIR ${DEPLOY_ROOT}

FROM nginx:1.27 AS web
ARG DEPLOY_ROOT
RUN groupadd -g 1000 site \
 && usermod -a -G site nginx \
 && mkdir -p "${DEPLOY_ROOT}" /etc/nginx/snippets
COPY nginx-vhost.conf /etc/nginx/conf.d/default.conf
COPY nginx-php.conf /etc/nginx/snippets/analytics-php.conf
