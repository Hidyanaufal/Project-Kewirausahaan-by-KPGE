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
    fonts-roboto \
    && rm -rf /var/lib/apt/lists/*

# Install wkhtmltopdf (fix for libssl1.1 dependency)
RUN apt-get update && apt-get install -y curl gnupg \
    && echo "deb http://security.debian.org/debian-security bullseye-security main" >> /etc/apt/sources.list \
    && apt-get update && apt-get install -y libssl1.1 || true \
    && curl -L -o /tmp/wkhtmltox.deb https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6-1/wkhtmltox_0.12.6-1.buster_amd64.deb \
    && apt-get install -y /tmp/wkhtmltox.deb \
    && rm -rf /var/lib/apt/lists/* /tmp/wkhtmltox.deb

# Find actual binary locations and create symlinks to expected paths for Laravel Snappy configuration
RUN mkdir -p /usr/local/bin \
    && WKHTMLTOPDF_PATH=$(which wkhtmltopdf 2>/dev/null || dpkg -L wkhtmltox 2>/dev/null | grep -E '/bin/wkhtmltopdf$' | head -1 || echo '/usr/bin/wkhtmltopdf') \
    && WKHTMLTOIMAGE_PATH=$(which wkhtmltoimage 2>/dev/null || dpkg -L wkhtmltox 2>/dev/null | grep -E '/bin/wkhtmltoimage$' | head -1 || echo '/usr/bin/wkhtmltoimage') \
    && test -f "$WKHTMLTOPDF_PATH" || (echo "Error: wkhtmltopdf not found. Package files:" && dpkg -L wkhtmltox | grep -E 'bin/' && exit 1) \
    && test -f "$WKHTMLTOIMAGE_PATH" || (echo "Error: wkhtmltoimage not found. Package files:" && dpkg -L wkhtmltox | grep -E 'bin/' && exit 1) \
    && ([ "$WKHTMLTOPDF_PATH" != "/usr/local/bin/wkhtmltopdf" ] && ln -sf "$WKHTMLTOPDF_PATH" /usr/local/bin/wkhtmltopdf || true) \
    && ([ "$WKHTMLTOIMAGE_PATH" != "/usr/local/bin/wkhtmltoimage" ] && ln -sf "$WKHTMLTOIMAGE_PATH" /usr/local/bin/wkhtmltoimage || true)

# Verify wkhtmltopdf and wkhtmltoimage are accessible at both original and symlinked paths
RUN test -f /usr/local/bin/wkhtmltopdf && /usr/local/bin/wkhtmltopdf --version \
    && test -f /usr/local/bin/wkhtmltoimage && /usr/local/bin/wkhtmltoimage --version

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd intl zip

# Copy composer from official image
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy app files
COPY --chown=www-data:www-data . /var/www

# Fix PHP-FPM user/group warning
RUN sed -i 's/^user =/;user =/' /usr/local/etc/php-fpm.d/www.conf \
 && sed -i 's/^group =/;group =/' /usr/local/etc/php-fpm.d/www.conf

# Expose port 9000
EXPOSE 9000

CMD ["php-fpm"]