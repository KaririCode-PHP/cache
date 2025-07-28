ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-alpine

# ---------------------------------------------------------------------------
# 1) Runtime-only dependencies (kept in final image)
# ---------------------------------------------------------------------------
RUN apk add --no-cache \
    libmemcached-libs \ 
    zlib 
# ---------------------------------------------------------------------------
# 2) Build-time dependencies (removed later)
# ---------------------------------------------------------------------------
RUN apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS \
    linux-headers \
    zlib-dev \
    libmemcached-dev \
    cyrus-sasl-dev \
    # -----------------------------------------------------------------------
    # 3) Install PECL extensions
    # -----------------------------------------------------------------------
    && pecl install \
    igbinary \
    memcached \
    redis \
    apcu \
    xdebug \
    && docker-php-ext-enable \
    igbinary memcached redis apcu xdebug \
    # -----------------------------------------------------------------------
    # 4) Clean up build packages
    # -----------------------------------------------------------------------
    && apk del .build-deps \
    && rm -rf /tmp/pear

# ---------------------------------------------------------------------------
# 5) Composer – copy binary from official image
# ---------------------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# ---------------------------------------------------------------------------
# 6) PHP configuration
# ---------------------------------------------------------------------------
COPY .docker/php/kariricode-php.ini /usr/local/etc/php/conf.d/kariricode-php.ini

# ---------------------------------------------------------------------------
# 7) Default working directory
# ---------------------------------------------------------------------------
WORKDIR /app

# ---------------------------------------------------------------------------
# 8) Default command (kept as “tail” for long-running dev containers)
# ---------------------------------------------------------------------------
CMD ["tail", "-f", "/dev/null"]
