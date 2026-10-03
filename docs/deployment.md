# Развёртывание

Nginx → PHP-FPM → приложение; PostgreSQL/MySQL; Redis; приватное файловое хранилище.

База данных, Redis и внутренние сервисы закрыты firewall. Production: `APP_DEBUG=false`, отдельные секреты, HTTPS, резервные копии и мониторинг.
