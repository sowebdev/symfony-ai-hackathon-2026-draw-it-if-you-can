# Everything runs in this image: FrankenPHP serves the game, and the same container runs
# bin/console, Composer and the tests. Nothing but Docker and make is needed on the machine.
# pdo_sqlite and opcache ship with the base image.
FROM dunglas/frankenphp:1.13-php8.5-trixie

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

# gd renders the drawing sent to the model.
RUN install-php-extensions intl zip gd

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Run as the machine's user (make passes its ids), so that files written in the mounted project
# belong to them. A port above 1024 needs no capability.
ARG HOST_UID=1000
ARG HOST_GID=1000
RUN groupadd --gid "${HOST_GID}" app 2>/dev/null || true \
    && useradd --uid "${HOST_UID}" --gid "${HOST_GID}" --create-home app \
    && setcap -r /usr/local/bin/frankenphp \
    && chown -R "${HOST_UID}:${HOST_GID}" /config/caddy /data/caddy
USER app
WORKDIR /app

# Plain HTTP on 8000 in development; SERVER_ROOT defaults to public/.
ENV SERVER_NAME=:8000
