# syntax=docker/dockerfile:1.9
#
# One Dockerfile for every image of the project (build context = repository root):
#
#   php-base      php 8.4-fpm with the runtime extensions and composer
#   php-dev       php-base + pcov + git/unzip        (dev and test compose)
#   node-build    node 24 + pnpm: tracker + dashboard build artefacts
#   vendor        composer install --no-dev (services/api)
#   release-tree  the release layout of plan §10.1
#   package       FROM scratch: dist/analytics-<TS>.tar.gz  (--output type=local)
#   php-runtime   production app image  (ghcr.io/manuto276/analytics-php)
#   nginx-runtime production web image  (ghcr.io/manuto276/analytics-web)
#
# Build arguments (deploy/manual/build.sh passes all four):
#   BUILD_TS      20260917T142501Z    package version / tar name
#   COMMIT        full git sha        REVISION + BUILD_INFO.json
#   COMMIT_DATE   ISO-8601            BUILD_INFO.json commit_date
#   COMMIT_TIME   unix timestamp      tar --mtime, for reproducible archives

ARG PHP_VERSION=8.4
ARG NODE_VERSION=24
ARG NGINX_VERSION=1.29
ARG COMPOSER_VERSION=2.8

FROM composer:${COMPOSER_VERSION} AS composer-bin

# ---------------------------------------------------------------- php-base ---
FROM php:${PHP_VERSION}-fpm AS php-base
# hadolint ignore=DL3008 # Debian point releases are not pinned on purpose: the base image tag pins the distribution.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      ca-certificates \
      libicu-dev \
      libzip-dev \
 && docker-php-ext-configure intl \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && pecl clear-cache \
 && apt-get purge -y --auto-remove \
 && rm -rf /var/lib/apt/lists/* /tmp/pear
# sodium and mbstring are built into the official image; OPcache too (configured per environment below).
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1
RUN groupadd --gid 1000 app \
 && useradd --uid 1000 --gid app --create-home --shell /bin/bash app
WORKDIR /app

# ----------------------------------------------------------------- php-dev ---
FROM php-base AS php-dev
# hadolint ignore=DL3008
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip default-mysql-client \
 && pecl install pcov \
 && docker-php-ext-enable pcov \
 && pecl clear-cache \
 && rm -rf /var/lib/apt/lists/* /tmp/pear
COPY deploy/docker/php/dev.ini /usr/local/etc/php/conf.d/zz-analytics.ini
COPY deploy/docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-analytics.conf
# Dev and test containers run as the mounted source owner (uid 1000 on the host).
USER app

# -------------------------------------------------------------- node-build ---
FROM node:${NODE_VERSION}-slim AS node-build
ENV COREPACK_ENABLE_DOWNLOAD_PROMPT=0 \
    PNPM_HOME=/pnpm \
    CI=1
RUN corepack enable
WORKDIR /src

# tracker: dependencies first so the layer is reused while only sources change
COPY services/tracker/package.json services/tracker/pnpm-lock.yaml /src/services/tracker/
WORKDIR /src/services/tracker
RUN --mount=type=cache,target=/pnpm/store,sharing=locked pnpm install --frozen-lockfile
COPY services/tracker/ /src/services/tracker/
RUN pnpm build

# dashboard: static SPA (`nuxt generate` → .output/public)
COPY services/dashboard/package.json services/dashboard/pnpm-lock.yaml services/dashboard/pnpm-workspace.yaml /src/services/dashboard/
WORKDIR /src/services/dashboard
RUN --mount=type=cache,target=/pnpm/store,sharing=locked pnpm install --frozen-lockfile
COPY docs/api/ /src/docs/api/
COPY services/dashboard/ /src/services/dashboard/
# `generate` is the portable entry point; the copy into services/api/public and the
# CSP hashes are done here (scripts/spa-csp.mjs), so a dashboard without the
# `generate:api` script still packages correctly.
RUN pnpm generate

COPY deploy/docker/scripts/spa-csp.mjs /scripts/spa-csp.mjs
WORKDIR /src
RUN mkdir -p /out \
 && cp services/tracker/dist/tracker.js /out/tracker.js \
 && cp services/tracker/dist/banner.js /out/banner.js \
 && cp -R services/dashboard/.output/public /out/spa \
 && node /scripts/spa-csp.mjs /out/spa /out/csp.php

# ------------------------------------------------------------------ vendor ---
FROM php-base AS vendor
WORKDIR /app
COPY services/api/composer.json services/api/composer.lock /app/
RUN --mount=type=cache,target=/tmp/composer-cache,sharing=locked \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer install \
      --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
COPY services/api/ /app/
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
 && composer check-platform-reqs --no-dev

# ------------------------------------------------------------ release-tree ---
FROM php-base AS release-tree
ARG BUILD_TS=dev
ARG COMMIT=0000000000000000000000000000000000000000
ARG COMMIT_DATE=1970-01-01T00:00:00+00:00
WORKDIR /release

# Backend (tests and dev tooling are never copied in)
COPY services/api/bin/ /release/bin/
COPY services/api/migrations/ /release/migrations/
COPY services/api/resources/ /release/resources/
COPY services/api/src/ /release/src/
COPY services/api/composer.json /release/composer.json
COPY services/api/public/index.php /release/public/index.php
COPY --from=vendor /app/vendor/ /release/vendor/

# Frontend artefacts
COPY --from=node-build /out/spa/ /release/public/
COPY --from=node-build /out/tracker.js /release/resources/tracker/tracker.js
COPY --from=node-build /out/banner.js /release/resources/tracker/banner.js
COPY --from=node-build /out/csp.php /release/config/csp.php

# Repository files that ship with a release
COPY LICENSE NOTICE /release/
COPY deploy/docker/env.example /release/.env.example
COPY deploy/manual/console /release/deploy/console
COPY deploy/examples/ /release/deploy/examples/

COPY deploy/docker/scripts/build-info.php /scripts/build-info.php
# Strip anything that is development-only, then write the manifest.
RUN rm -rf /release/tests /release/var \
 && rm -f /release/.env /release/.env.local \
          /release/phpunit.xml.dist /release/phpstan.neon.dist /release/phpstan-tests.neon.dist \
          /release/rector.php /release/deptrac.yaml /release/.php-cs-fixer.dist.php /release/composer.lock \
 && mkdir -p /release/var/cache \
 && chmod 0755 /release/bin/analytics /release/deploy/console \
 && php /scripts/build-info.php /release "${BUILD_TS}" "${COMMIT}" "${COMMIT_DATE}" \
 && php -l /release/config/csp.php > /dev/null

# ----------------------------------------------------------------- tarball ---
FROM php-base AS tarball
ARG BUILD_TS=dev
ARG COMMIT_TIME=0
SHELL ["/bin/bash", "-o", "pipefail", "-c"]
COPY --from=release-tree /release/ /release/
WORKDIR /release
# Reproducible archive: sorted entries, no owner names, commit time as mtime, gzip without a name/timestamp header.
RUN mkdir -p /out \
 && tar --sort=name --owner=0 --group=0 --numeric-owner --mtime="@${COMMIT_TIME}" -cf - . \
      | gzip -n > "/out/analytics-${BUILD_TS}.tar.gz"

# `docker buildx build --target package --output type=local,dest=dist` → dist/analytics-<TS>.tar.gz
FROM scratch AS package
COPY --from=tarball /out/ /

# ------------------------------------------------------------- php-runtime ---
FROM php-base AS php-runtime
ARG BUILD_TS=dev
ARG COMMIT=unknown
# hadolint ignore=DL3008
RUN apt-get update \
 && apt-get install -y --no-install-recommends libfcgi-bin \
 && rm -rf /var/lib/apt/lists/*
COPY deploy/docker/php/prod.ini /usr/local/etc/php/conf.d/zz-analytics.ini
COPY deploy/docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-analytics.conf
COPY deploy/docker/scripts/fpm-healthcheck.sh /usr/local/bin/fpm-healthcheck
COPY --from=release-tree --chown=1000:1000 /release/ /app/
RUN chmod 0755 /usr/local/bin/fpm-healthcheck \
 && mkdir -p /app/var/cache /app/var/log /app/var/storage \
 && chown -R app:app /app/var
WORKDIR /app
USER app
EXPOSE 9000
# Light check: the FPM pool's own ping endpoint over FastCGI (no application boot, no database).
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 CMD ["fpm-healthcheck"]
LABEL org.opencontainers.image.title="analytics-php" \
      org.opencontainers.image.description="Self-hosted privacy-first web analytics (PHP-FPM application image)" \
      org.opencontainers.image.source="https://github.com/manuto276/analytics" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later"
CMD ["php-fpm", "--nodaemonize"]

# ------------------------------------------------------------- php-cron ------
# Same application image plus supercronic, for the `scheduler` service of
# compose.prod.yml (plan §9 schedule in deploy/docker/cron/crontab).
FROM php-runtime AS php-cron
ARG SUPERCRONIC_VERSION=v0.2.49
ARG SUPERCRONIC_SHA256_AMD64=a53ae236602c7338aba3fbaff40bda6300eae3b9fedb8261eb06cfe3724430c1
ARG SUPERCRONIC_SHA256_ARM64=02aa0cb229ba09050cba6638059dadb9eedc2276632ea43d6a57a2f8c1629dd5
USER root
# hadolint ignore=DL3008
RUN apt-get update \
 && apt-get install -y --no-install-recommends curl \
 && arch="$(dpkg --print-architecture)" \
 && case "$arch" in \
      amd64) sha="${SUPERCRONIC_SHA256_AMD64}" ;; \
      arm64) sha="${SUPERCRONIC_SHA256_ARM64}" ;; \
      *) echo "unsupported architecture: $arch" >&2; exit 1 ;; \
    esac \
 && curl -fsSLo /usr/local/bin/supercronic \
      "https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-${arch}" \
 && printf '%s  /usr/local/bin/supercronic\n' "$sha" > /tmp/supercronic.sha256 \
 && sha256sum -c /tmp/supercronic.sha256 \
 && rm -f /tmp/supercronic.sha256 \
 && chmod 0755 /usr/local/bin/supercronic \
 && apt-get purge -y --auto-remove curl \
 && rm -rf /var/lib/apt/lists/*
COPY deploy/docker/cron/crontab /etc/analytics/crontab
USER app
HEALTHCHECK NONE
CMD ["supercronic", "-passthrough-logs", "/etc/analytics/crontab"]

# ----------------------------------------------------------- nginx-runtime ---
FROM nginx:${NGINX_VERSION}-alpine AS nginx-runtime
COPY deploy/docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
COPY deploy/docker/nginx/snippets/ /etc/nginx/snippets/
# Same absolute path as in php-runtime: $realpath_root must resolve identically in both containers.
COPY --from=release-tree /release/public/ /app/public/
LABEL org.opencontainers.image.title="analytics-web" \
      org.opencontainers.image.description="Self-hosted privacy-first web analytics (nginx image with the static dashboard)" \
      org.opencontainers.image.source="https://github.com/manuto276/analytics" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later"
