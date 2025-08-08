# Dockerfile
FROM php:8.4-cli

# 1) Zainstaluj biblioteki deweloperskie i narzędzia
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    libxml2-dev \          
    libcurl4-openssl-dev \ 
    libonig-dev \          
    pkg-config \           
    libsqlite3-dev \       
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# 2) Skonfiguruj i zainstaluj rozszerzenia PHP
RUN docker-php-ext-install \
    mbstring \            
    dom \                 
    pdo_sqlite \          
    curl                  

# 3) Composer i Symfony CLI
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN curl -sS https://get.symfony.com/cli/installer | bash \
    && mv /root/.symfony5/bin/symfony /usr/local/bin/symfony

# 4) Ustaw katalog roboczy i skopiuj projekt
WORKDIR /app
COPY . .

# 5) Instalacja zależności PHP
RUN composer install --no-interaction --optimize-autoloader

# 6) Domyślna komenda: serwer Symfony na porcie 8000
# CMD ["symfony", "server:start", "--no-tls", "--port=8000"]
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
