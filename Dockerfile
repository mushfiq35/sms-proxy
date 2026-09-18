FROM php:8.2-apache

# Install curl dev headers + pkg-config first (php-ext-install curl needs
# these to build the extension), then enable the curl extension itself.
RUN apt-get update && \
    apt-get install -y --no-install-recommends libcurl4-openssl-dev pkg-config && \
    docker-php-ext-install curl && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# Copy everything in this folder (sms_proxy.php, ip_check.php, any future
# .php files) into Apache's web root, so new files show up automatically
# without editing the Dockerfile again.
COPY . /var/www/html/
RUN rm -f /var/www/html/Dockerfile /var/www/html/start.sh /var/www/html/README.md /var/www/html/.gitattributes
RUN echo "SMS Proxy is running." > /var/www/html/index.html

# Render assigns a random $PORT at runtime and expects the app to listen on
# it, so we rewrite Apache's config to that port right before starting.
COPY start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 80
CMD ["/start.sh"]
