# ══════════════════════════════════════════════════════════════════
#  ABDO-TECK POS — Multi-stage Dockerfile
# ══════════════════════════════════════════════════════════════════

# ── Stage 1: Build Frontend ────────────────────────────────────────
FROM node:22-alpine AS frontend-builder

WORKDIR /build

# نسخ ملفات الإعتماديات أولاً (cache layer)
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --silent

# نسخ كود المصدر وبناء الإنتاج
COPY frontend/ ./
RUN npm run build


# ── Stage 2: Install Backend Dependencies ──────────────────────────
FROM composer:2 AS backend-builder

WORKDIR /build

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY backend/ ./


# ── Stage 3: Production Image ─────────────────────────────────────
FROM php:8.2-apache AS production

# تثبيت الإضافات المطلوبة
RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql zip gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# تفعيل mod_rewrite و mod_headers
RUN a2enmod rewrite headers

# إعداد Apache VirtualHost
RUN echo '<VirtualHost *:80>\n\
    ServerName localhost\n\
    DocumentRoot /var/www/html/frontend-dist\n\
    \n\
    <Directory /var/www/html/frontend-dist>\n\
        Options -Indexes +FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
        RewriteEngine On\n\
        RewriteCond %{REQUEST_FILENAME} !-f\n\
        RewriteCond %{REQUEST_FILENAME} !-d\n\
        RewriteCond %{REQUEST_URI} !^/api/\n\
        RewriteRule . /index.html [L]\n\
    </Directory>\n\
    \n\
    Alias /api /var/www/html/backend\n\
    <Directory /var/www/html/backend>\n\
        Options -Indexes +FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    \n\
    <Directory /var/www/html/backend/storage>\n\
        Require all denied\n\
    </Directory>\n\
    <Directory /var/www/html/backend/logs>\n\
        Require all denied\n\
    </Directory>\n\
    \n\
    <FilesMatch "(^\\.|\\.(env|log|sql|md|pem|key|sqlite|sqlite-wal|sqlite-shm)$)">\n\
        Require all denied\n\
    </FilesMatch>\n\
    \n\
    Header always set X-Content-Type-Options "nosniff"\n\
    Header always set X-Frame-Options "DENY"\n\
    Header always set X-XSS-Protection "1; mode=block"\n\
    Header always set Referrer-Policy "strict-origin-when-cross-origin"\n\
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# إعداد PHP للإنتاج
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /var/www/html

# نسخ الباك إند (من مرحلة البناء)
COPY --from=backend-builder /build/ ./backend/

# حذف Adminer من صورة الإنتاج — لا حاجة لأداة إدارة قواعد البيانات
RUN rm -f ./backend/adminer.php ./backend/adminer-local.php

# نسخ الفرونت إند المبني (من مرحلة البناء)
COPY --from=frontend-builder /build/dist/ ./frontend-dist/

# نسخ ملفات قاعدة البيانات
COPY database/ ./database/

COPY docker-entrypoint.sh /usr/local/bin/pos-entrypoint
RUN chmod 0755 /usr/local/bin/pos-entrypoint

# إنشاء مجلد اللوج
RUN mkdir -p ./backend/logs && chown -R www-data:www-data ./backend/logs

# ضبط الصلاحيات
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["pos-entrypoint"]
