FROM php:8.5-fpm

# Arguments
ARG user=www
ARG uid=1000

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    zip \
    unzip \
    libmagickwand-dev \
    poppler-utils \
    tesseract-ocr \
    tesseract-ocr-slk \
    tesseract-ocr-ces \
    tesseract-ocr-eng \
    # Saxon (Java) runs the official EN 16931 Schematron in
    # tests/Feature/Invoicing/UblSchematronTest.php. PHP cannot: the compiled
    # Schematron is XSLT 2.0 and libxslt only implements 1.0, so php-xsl does
    # not help — this was measured, not assumed. The headless JRE is the cost
    # of having the strongest correctness check in the e-invoicing path run
    # on every suite run instead of being skipped.
    default-jre-headless \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        mbstring \
        bcmath \
        zip \
        xml

# No opcache step: since PHP 8.5 the extension is compiled into the binary and
# always loaded, so there is no opcache.so left to enable — the old
# `docker-php-ext-enable opcache` now fails the build outright. docker/php/
# opcache.ini still applies; opcache.enable/enable_cli are honoured as before.

# Install exif and pcntl separately
RUN docker-php-ext-install exif pcntl

# Install Imagick extension via PECL
RUN pecl install imagick && docker-php-ext-enable imagick

# Install Redis extension via PECL
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:2.9.1 /usr/bin/composer /usr/bin/composer

# Create system user
RUN useradd -G www-data,root -u $uid -d /home/$user $user \
    && mkdir -p /home/$user/.composer \
    && chown -R $user:$user /home/$user

# Set working directory
WORKDIR /var/www

# Copy PHP config
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

USER $user
