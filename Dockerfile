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

# 5) Utwórz minimalny plik .env dla composer
RUN echo "APP_ENV=prod" > .env && \
    echo "DATABASE_URL=sqlite:///app/var/data_prod.db" >> .env

# 6) Instalacja zależności PHP (bez wywoływania skryptów post-install)
RUN composer install --no-interaction --optimize-autoloader --no-dev --no-scripts

# 7) Domyślna komenda: serwer Symfony na porcie 8000
# CMD ["symfony", "server:start", "--no-tls", "--port=8000"]
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
