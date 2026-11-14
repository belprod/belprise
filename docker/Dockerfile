# Belprise — Apache + PHP image (development and NAS production).
# Build context: the docker/ directory (never the data).

FROM php:8.3-apache AS base

# pdo_sqlite, mbstring, fileinfo and openssl ship with the official image.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libapache2-mod-xsendfile \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers xsendfile \
    && a2dissite 000-default \
    && a2disconf other-vhosts-access-log

COPY apache/belprise.conf /etc/apache2/sites-available/belprise.conf
COPY apache/security.conf /etc/apache2/conf-available/zz-belprise-security.conf
COPY php/belprise.ini /usr/local/etc/php/conf.d/belprise.ini

RUN a2ensite belprise && a2enconf zz-belprise-security

WORKDIR /var/www/belprise

# Production: the dist/ archive (app/, public/, data/) is mounted or copied
# into /var/www/belprise; data/ must be writable by www-data.
FROM base AS prod

# Development: adds Composer (PHPUnit is a dev dependency only).
FROM base AS dev
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
# Site vitrine on port 8090 (in production, belprise.fr has its own host).
COPY apache/site.conf /etc/apache2/sites-available/belprise-site.conf
RUN a2ensite belprise-site
