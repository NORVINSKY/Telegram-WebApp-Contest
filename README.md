# Telegram WebApp Contest

Эта штуку навайбкодил за пару часов в Antigravity от гугла для проведения конкурса в телеграм чате. За основу были взяты корейские тесты по типу piku.co.kr. Правда я не до конца разобрался в математике и сделал формирование тир-листа по принципам ELO, что может немного руинить голосование и кому-то даже может показаться несправедливым. Но это не страшно, так как всегда можно выровнять сетку по винрейту.

### Установка:

- У меня все крутится в докере на OpenMediaVault 7. Домен проброшен через NginxProxyManager. Конфиг:

  ```
  version: '3'
  services:
    app:
      image: adhocore/lemp:7.4
      container_name: LEMP
      restart: unless-stopped
      volumes:
        - /SSD/websrv/public:/var/www/html
        # Монтируем исправленный конфиг
        - /SSD/websrv/nginx/fixed.conf:/etc/nginx/conf.d/default.conf
        - db_data:/var/lib/mysql
      ports:
        - 89:80
      environment:
        MYSQL_ROOT_PASSWORD: supersecurepwd
        MYSQL_DATABASE: appdb
        MYSQL_USER: dbusr
        MYSQL_PASSWORD: securepwd
  
  volumes:
    db_data: {}
  ```

- Конфиг nginx:

  ```
  server {
      listen 80;
      server_name _;
      
      root /var/www/html;
      index index.html index.php;
      
      client_max_body_size 5M;
      
      access_log /var/log/nginx/access.log;
      error_log /var/log/nginx/error.log warn;
      
      # КРИТИЧЕСКИ ВАЖНО: Правильный обработчик uploads с блокировкой PHP
      location ^~ /uploads/ {
          # Отключаем ВСЮ обработку PHP в этой папке
          location ~ \.php$ {
              deny all;
              return 403;
          }
          
          # Просто отдаем файлы как статику
          try_files $uri =404;
          
          # Отключаем автоиндекс
          autoindex off;
          
          # Настраиваем кэширование для изображений
          expires 30d;
          add_header Cache-Control "public, immutable";
          
          # Явно указываем MIME-типы
          types {
              image/jpeg jpg jpeg;
              image/png png;
              image/gif gif;
              image/webp webp;
              image/svg+xml svg;
          }
      }
      
      # Запрет доступа к служебным директориям
      location ~ ^/(config|src|db)/ {
          deny all;
          return 404;
      }
      
      # Обработка PHP в /public/
      location ~ ^/public/.*\.php$ {
          try_files $uri =404;
          fastcgi_pass 127.0.0.1:9000;
          fastcgi_index index.php;
          fastcgi_param SCRIPT_FILENAME $document_root$uri;
          include fastcgi_params;
          
          add_header Access-Control-Allow-Origin "*" always;
          add_header Access-Control-Allow-Methods "GET, POST, OPTIONS" always;
          add_header Access-Control-Allow-Headers "Content-Type, Authorization" always;
      }
      
      # Редирект корневых запросов в /public/
      location / {
          try_files /public$uri /public$uri/ /public/index.html;
      }
      
      # Обработка ТОЛЬКО PHP файлов (исправленная версия)
      location ~ \.php$ {
          # Проверяем, что это действительно PHP файл в нужных местах
          if ($uri !~ "^/(public/|index\.php|api\.php|admin\.php|bot\.php)") {
              return 404;
          }
          
          # Проверяем существование файла
          if (!-f $document_root/public$uri) {
              return 404;
          }
          
          fastcgi_pass 127.0.0.1:9000;
          fastcgi_index index.php;
          fastcgi_param SCRIPT_FILENAME $document_root/public$uri;
          include fastcgi_params;
          
          add_header Access-Control-Allow-Origin "*" always;
          add_header Access-Control-Allow-Methods "GET, POST, OPTIONS" always;
          add_header Access-Control-Allow-Headers "Content-Type, Authorization" always;
      }
  }
  ```

  {red}(Смотри только внимательно, чтоб все пути соответствовали системе и структуре папок/файлов.)

- Не забудь выдать права на запись для папок /uploads и /db (так же на файл database.sqlite)

- В файле /config/config.php задай пароль для админки и пропиши ключ к своему Telegram боту

  - Так же в этом файле можно включить DEV режим (можно будет из админки сбросить все результаты)

- После всех настроек запустить скрипт /bot.php?setup=webhook

### Превью:

  ![](https://raw.githubusercontent.com/NORVINSKY/Telegram-WebApp-Contest/refs/heads/main/readme/1.jpg "Общая статистика")

  ![](https://raw.githubusercontent.com/NORVINSKY/Telegram-WebApp-Contest/refs/heads/main/readme/2.jpg "Управление кандидатами")

  ![](https://github.com/NORVINSKY/Telegram-WebApp-Contest/blob/main/readme/3.jpg?raw=true "Результаты голосования")

  ![](https://github.com/NORVINSKY/Telegram-WebApp-Contest/blob/main/readme/4.jpg?raw=true "Ход голосования и комментарии")

  ![](https://github.com/NORVINSKY/Telegram-WebApp-Contest/blob/main/readme/anima.gif?raw=true "Как это выглядит")
