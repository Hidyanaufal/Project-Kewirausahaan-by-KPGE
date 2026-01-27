FROM php:8.3-fpm

# Install dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libicu-dev \
    libzip-dev \
    libxrender1 \
    libxext6 \
    libfontconfig1 \
    xfonts-base \
    xfonts-75dpi \
    zip \
    unzip \
    git \
    curl \
    fontconfig \
    && rm -rf /var/lib/apt/lists/*

# Install wkhtmltopdf (fix for libssl1.1 dependency)
RUN apt-get update && apt-get install -y curl gnupg \
    && echo "deb http://security.debian.org/debian-security bullseye-security main" >> /etc/apt/sources.list \
    && apt-get update && apt-get install -y libssl1.1 || true \
    && curl -L -o /tmp/wkhtmltox.deb https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6-1/wkhtmltox_0.12.6-1.buster_amd64.deb \
    && apt-get install -y /tmp/wkhtmltox.deb \
    && rm -rf /var/lib/apt/lists/* /tmp/wkhtmltox.deb

# Create symlinks to expected paths for Laravel Snappy configuration
RUN mkdir -p /usr/local/bin \
    && ln -sf /usr/bin/wkhtmltopdf /usr/local/bin/wkhtmltopdf \
    && ln -sf /usr/bin/wkhtmltoimage /usr/local/bin/wkhtmltoimage

# Verify wkhtmltopdf and wkhtmltoimage are accessible at expected paths
RUN /usr/local/bin/wkhtmltopdf --version && /usr/local/bin/wkhtmltoimage --version

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd intl zip

# Copy composer from official image
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy app files
COPY --chown=www-data:www-data . /var/www

# Expose port 9000
EXPOSE 9000

CMD ["php-fpm"]
