# 1TechLink Social Hub — production image for Render.
#
# Layout is kept identical to local dev (repo lives at
# /var/www/html/PHP_projects/social-hub, DocumentRoot /var/www/html) so every
# baked-in path (/PHP_projects/social-hub/public/...) works unchanged and no
# frontend rebuild is needed. The committed public/dist bundle is served as-is.
FROM php:8.4-apache

# PHP extensions the app needs (pdo_mysql, pdo_pgsql, mbstring, curl, gd, fileinfo).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libjpeg-dev libfreetype6-dev libpq-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql mbstring gd \
    && docker-php-ext-enable opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Render terminates TLS at its edge and forwards plain HTTP internally.
# Tell PHP the original request was HTTPS so session cookies are Secure and
# live OAuth (which requires https) is allowed. Safe here because the
# container is only reachable through Render's proxy.
RUN printf '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html\n\
    SetEnvIf X-Forwarded-Proto "https" HTTPS=on\n\
    RewriteEngine On\n\
    RewriteRule "^/$" "/PHP_projects/social-hub/public/index.php" [L]\n\
    <Directory /var/www/html>\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
</VirtualHost>\n' > /etc/apache2/sites-available/000-default.conf

# Keep the exact local path layout.
COPY . /var/www/html/PHP_projects/social-hub/
WORKDIR /var/www/html/PHP_projects/social-hub

# Writable runtime dirs for the www-data user.
RUN mkdir -p storage/uploads storage/logs storage/sessions \
    && chown -R www-data:www-data storage \
    && chmod -R 775 storage

# If APP_URL is not set explicitly, derive it from Render's own public URL so
# OAuth redirect URIs and media links are correct without manual config.
COPY deploy/docker-entrypoint.sh /usr/local/bin/socialhub-entrypoint
RUN chmod +x /usr/local/bin/socialhub-entrypoint

EXPOSE 80
ENTRYPOINT ["socialhub-entrypoint"]
CMD ["apache2-foreground"]
