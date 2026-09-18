FROM php:8.2-apache

# Enable curl extension (needed by sms_proxy.php)
RUN docker-php-ext-install curl

# Copy the proxy script into Apache's web root
COPY sms_proxy.php /var/www/html/sms_proxy.php
RUN echo "SMS Proxy is running." > /var/www/html/index.html

# Render assigns a random $PORT at runtime and expects the app to listen on
# it, so we rewrite Apache's config to that port right before starting.
COPY start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 80
CMD ["/start.sh"]
