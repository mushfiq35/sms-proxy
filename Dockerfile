FROM php:8.2-apache

# Install curl dev headers + pkg-config first (php-ext-install curl needs
# these to build the extension), then enable the curl extension itself.
RUN apt-get update && \
    apt-get install -y --no-install-recommends libcurl4-openssl-dev pkg-config && \
    docker-php-ext-install curl && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# Copy the proxy script into Apache's web root
COPY sms_proxy.php /var/www/html/sms_proxy.php
RUN echo "SMS Proxy is running." > /var/www/html/index.html

# Render assigns a random $PORT at runtime and expects the app to listen on
# it, so we rewrite Apache's config to that port right before starting.
COPY start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 80
CMD ["/start.sh"]
