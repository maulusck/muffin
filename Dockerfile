FROM alpine:3.20

# ----------------------------
# Install Apache + PHP-FPM + extensions
# ----------------------------
RUN apk add --no-cache \
    apache2 \
    apache2-proxy \
    php82 \
    php82-fpm \
    php82-opcache \
    php82-json \
    php82-curl \
    php82-phar \
    php82-openssl \
    php82-zip \
    php82-simplexml

# ----------------------------
# Apache base config
# ----------------------------
RUN sed -i \
    -e 's#^DocumentRoot .*#DocumentRoot "/var/www/html"#' \
    /etc/apache2/httpd.conf

RUN echo "ServerName localhost" >> /etc/apache2/httpd.conf

# ----------------------------
# Critical fix: permissions + index + no directory listing
# ----------------------------
RUN printf '%s\n' \
    '<Directory "/var/www/html">' \
    '    Require all granted' \
    '    AllowOverride All' \
    '    Options -Indexes +FollowSymLinks' \
    '    DirectoryIndex index.php index.html' \
    '</Directory>' \
    > /etc/apache2/conf.d/nuget.conf

# ----------------------------
# PHP-FPM config
# ----------------------------
RUN sed -i \
    's|listen = .*|listen = 127.0.0.1:9000|' \
    /etc/php82/php-fpm.d/www.conf

# ----------------------------
# PHP handler via Apache proxy_fcgi
# ----------------------------
RUN printf '%s\n' \
    '<FilesMatch "\.php$">' \
    '    SetHandler "proxy:fcgi://127.0.0.1:9000"' \
    '</FilesMatch>' \
    > /etc/apache2/conf.d/php.conf

# ----------------------------
# Enable Apache modules (Alpine-safe)
# ----------------------------
RUN sed -i \
    -e 's/#LoadModule proxy_module/LoadModule proxy_module/' \
    -e 's/#LoadModule proxy_fcgi_module/LoadModule proxy_fcgi_module/' \
    -e 's/#LoadModule rewrite_module/LoadModule rewrite_module/' \
    /etc/apache2/httpd.conf

# ----------------------------
# App
# ----------------------------
WORKDIR /var/www/html
COPY webroot/index.php .
COPY webroot/config.php .
COPY webroot/rescan.php .
COPY webroot/.htaccess .

RUN chown -R apache:apache /var/www/html

EXPOSE 80

# ----------------------------
# Start services
# ----------------------------
CMD sh -c "php-fpm82 -D && httpd -D FOREGROUND"