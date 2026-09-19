FROM php:8.2-apache

# Enable Apache rewrite engine for clean routing
RUN a2enmod rewrite

# Install PDO MySQL extension (or pdo_pgsql if using PostgreSQL)
RUN docker-php-ext-install pdo pdo_mysql

# Set Apache root directory to /public where index.php and assets live
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/conf-available/*.conf

# Allow Apache to override folder permissions (.htaccess support)
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Copy all project files into the container
COPY . /var/www/html/

# Expose port 80
EXPOSE 80
