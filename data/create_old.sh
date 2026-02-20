#!/bin/bash
# create_site.sh - Ubuntu 24.04 + Apache + MySQL + Certbot + vsftpd
# Usage: sudo bash create_site.sh art

set -euo pipefail
IFS=$'\n\t'

# -----------------------
#  Настройки / переменные
# -----------------------

if [ -z "${1:-}" ]; then
  echo "Использование: $0 <sitename>"
  exit 1
fi

SITENAME="$1"

# Валидация имени сайта (только буквы, цифры, дефис, подчёркивание)
if ! [[ "$SITENAME" =~ ^[a-zA-Z0-9_-]+$ ]]; then
  echo "Ошибка: Имя сайта может содержать только буквы, цифры, дефис и подчёркивание"
  exit 1
fi

DOMAIN="$SITENAME.freedomvibe.net"
WWWDOMAIN="www.$DOMAIN"
WEBROOT="/var/www/$SITENAME"
APACHE_CONF="/etc/apache2/sites-available/${SITENAME}.freedomvibe.net.conf"
APACHE_TEMP_CONF="/etc/apache2/sites-available/${SITENAME}_temp.conf"
DB_NAME="$SITENAME"
DB_USER="$SITENAME"
CRED_MASTER="/var/www/cred_${SITENAME}.txt"

# Email для certbot
CERTBOT_EMAIL="${CERTBOT_EMAIL:-olegfreedom777@gmail.com}"

# -----------------------
#  Проверка прав (root)
# -----------------------

if [ "$EUID" -ne 0 ]; then
  echo "Ошибка: Скрипт требует root-привилегий. Запустите через sudo."
  exit 1
fi

# -----------------------
#  Функция для выполнения MySQL команд
# -----------------------
mysql_exec() {
  if [ -f /etc/mysql/debian.cnf ]; then
    mysql --defaults-file=/etc/mysql/debian.cnf "$@" && return 0
  fi
  mysql "$@" && return 0
  mysql -u root "$@" && return 0
  echo "ОШИБКА: Не могу подключиться к MySQL" >&2
  return 1
}

# -----------------------
#  Проверка наличия утилит
# -----------------------

for cmd in apache2ctl systemctl openssl certbot; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "Ошибка: Требуется установить: $cmd"
    exit 1
  fi
done

# Проверка MySQL
if ! command -v mysql >/dev/null 2>&1; then
  echo "Ошибка: MySQL не установлен"
  exit 1
fi

# Тестируем подключение к MySQL
echo "Проверка подключения к MySQL..."
if ! mysql_exec -e "SELECT 1" >/dev/null 2>&1; then
  echo "Ошибка: Не удается подключиться к MySQL"
  echo "Проверьте, что MySQL запущен: systemctl status mysql"
  echo "И попробуйте вручную: mysql --defaults-file=/etc/mysql/debian.cnf -e 'SELECT 1'"
  exit 1
fi
echo "✓ MySQL подключение работает"

# Проверка DNS утилит
RESOLVER_CMD=""
if command -v dig >/dev/null 2>&1; then
  RESOLVER_CMD="dig"
elif command -v getent >/dev/null 2>&1; then
  RESOLVER_CMD="getent"
else
  echo "Ошибка: Нужна утилита 'dig' или 'getent' для проверки DNS."
  echo "Установите: apt install dnsutils"
  exit 1
fi

resolve_a_record() {
  local name="$1"
  if [ "$RESOLVER_CMD" = "dig" ]; then
    dig +short A "$name" | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' || return 1
  else
    getent hosts "$name" | awk '{print $1}' || return 1
  fi
}

# -----------------------
#  Функция полного отката при ошибке
# -----------------------

cleanup_on_error() {
  echo ""
  echo "⚠ Произошла ошибка. Выполняется откат изменений..."
  
  # Отключение и удаление конфигов Apache
  a2dissite "${SITENAME}_temp.conf" >/dev/null 2>&1 || true
  a2dissite "${SITENAME}.freedomvibe.net.conf" >/dev/null 2>&1 || true
  rm -f "$APACHE_TEMP_CONF" "$APACHE_CONF"
  systemctl reload apache2 2>/dev/null || true
  
  # Удаление пользователя
  if id -u "$SITENAME" >/dev/null 2>&1; then
    userdel "$SITENAME" 2>/dev/null || true
    echo "✓ Пользователь удалён"
  fi
  
  # Удаление webroot
  if [ -d "$WEBROOT" ]; then
    rm -rf "$WEBROOT"
    echo "✓ Webroot удалён"
  fi
  
  # Удаление базы данных
  SQL_CLEANUP="
DROP DATABASE IF EXISTS \`${DB_NAME}\`;
DROP USER IF EXISTS '${DB_USER}'@'localhost';
DROP USER IF EXISTS '${DB_USER}'@'%';
FLUSH PRIVILEGES;
"
  printf '%s' "$SQL_CLEANUP" | mysql_exec 2>/dev/null || true
  
  # Удаление файла с кредами
  rm -f "$CRED_MASTER"
  
  echo "✓ Откат завершён"
  exit 1
}

# Установка trap для отката при ошибке
trap cleanup_on_error ERR

# -----------------------
#  Проверка DNS A-записей
# -----------------------

echo "Проверка DNS для $DOMAIN и $WWWDOMAIN..."

if ! resolve_a_record "$DOMAIN" >/dev/null 2>&1; then
  echo "Ошибка: A-запись для $DOMAIN не найдена."
  echo "Добавьте A/CNAME запись и повторите."
  exit 1
fi

if ! resolve_a_record "$WWWDOMAIN" >/dev/null 2>&1; then
  echo "Ошибка: A/CNAME запись для $WWWDOMAIN не найдена."
  echo "Добавьте запись и повторите."
  exit 1
fi

echo "✓ DNS записи обнаружены"

# -----------------------
#  Предварительные проверки
# -----------------------

if id -u "$SITENAME" >/dev/null 2>&1; then
  echo "Ошибка: Пользователь $SITENAME уже существует."
  exit 1
fi

if [ -f "$APACHE_CONF" ]; then
  echo "Ошибка: Apache конфиг $APACHE_CONF уже существует."
  exit 1
fi

if [ -L "/etc/apache2/sites-enabled/${SITENAME}.freedomvibe.net.conf" ] || \
   [ -L "/etc/apache2/sites-enabled/${SITENAME}_temp.conf" ]; then
  echo "Ошибка: В sites-enabled уже есть симлинки для $SITENAME."
  echo "Удалите их: a2dissite ${SITENAME}.freedomvibe.net.conf"
  exit 1
fi

if mysql_exec -e "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$DB_NAME'" | grep -q "$DB_NAME"; then
  echo "Ошибка: База данных $DB_NAME уже существует."
  exit 1
fi

# -----------------------
#  Генерация пароля
# -----------------------

PASSWORD="$(openssl rand -base64 24)"
echo "✓ Пароль сгенерирован"

# -----------------------
#  Создание пользователя и webroot
# -----------------------

echo "Создание webroot $WEBROOT и пользователя $SITENAME..."

mkdir -p "$WEBROOT"
useradd -d "$WEBROOT" -s /bin/bash -g www-data "$SITENAME"
echo "$SITENAME:$PASSWORD" | chpasswd

chown -R "$SITENAME":www-data "$WEBROOT"
chmod -R 2775 "$WEBROOT"

echo "✓ Пользователь и webroot созданы"

# -----------------------
#  Создание sitelog
# -----------------------

SITELOG="$WEBROOT/sitelog"
echo "Создание каталога логов $SITELOG..."

mkdir -p "$SITELOG"
chown www-data:root "$SITELOG"
chmod 750 "$SITELOG"
chmod g-s "$SITELOG"

# ACL для $SITENAME: чтение каталога и файлов
if command -v setfacl >/dev/null 2>&1; then
  setfacl -m u:"$SITENAME":rx "$SITELOG"
  setfacl -d -m u::rwX,g::r-x,o::--- "$SITELOG"
  setfacl -d -m u:"$SITENAME":r- "$SITELOG"
  echo "✓ ACL настроены для sitelog"
else
  echo "⚠ setfacl не найден - рекомендуется установить: apt install acl"
fi

# -----------------------
#  Создание временного Apache конфига (только HTTP для certbot)
# -----------------------

echo "Создание временного Apache конфигурации для certbot..."

cat > "$APACHE_TEMP_CONF" <<EOF
<VirtualHost *:80>
    ServerName $DOMAIN
    ServerAlias $WWWDOMAIN
    DocumentRoot $WEBROOT
    
    <Directory $WEBROOT>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
EOF

# -----------------------
#  Включение временного сайта для certbot
# -----------------------

echo "Включение временного конфига..."

a2ensite "${SITENAME}_temp.conf" >/dev/null
apache2ctl configtest
systemctl reload apache2

echo "✓ Временный конфиг активирован"

# -----------------------
#  Запрос SSL сертификата
# -----------------------

echo "Запрос SSL сертификата через certbot..."

if ! certbot certonly --webroot -w "$WEBROOT" -d "$DOMAIN" -d "$WWWDOMAIN" --non-interactive --agree-tos -m "$CERTBOT_EMAIL"; then
  echo "⚠ Certbot вернул ошибку. Проверьте /var/log/letsencrypt/"
  echo "Откат изменений будет выполнен автоматически..."
  exit 1
fi

echo "✓ SSL сертификат получен"

# -----------------------
#  Отключение временного конфига
# -----------------------

echo "Удаление временного конфига..."

a2dissite "${SITENAME}_temp.conf" >/dev/null
rm -f "$APACHE_TEMP_CONF"

# -----------------------
#  Создание основного Apache конфига (HTTP + HTTPS)
# -----------------------

echo "Создание основного Apache конфигурации..."

cat > "$APACHE_CONF" <<EOF
<VirtualHost *:80>
    ServerName $DOMAIN
    ServerAlias $WWWDOMAIN
    DocumentRoot $WEBROOT
    
    <Directory $WEBROOT>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    RewriteEngine on
    RewriteCond %{SERVER_NAME} =$DOMAIN [OR]
    RewriteCond %{SERVER_NAME} =$WWWDOMAIN
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
</VirtualHost>

<VirtualHost *:443>
    ServerName $DOMAIN
    ServerAlias $WWWDOMAIN
    DocumentRoot $WEBROOT
    
    <Directory $WEBROOT>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/$DOMAIN/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/$DOMAIN/privkey.pem
    Include /etc/letsencrypt/options-ssl-apache.conf
    
    ErrorLog $SITELOG/${SITENAME}_error.log
    CustomLog $SITELOG/${SITENAME}_access.log combined
</VirtualHost>
EOF

echo "✓ Основной Apache конфиг создан"

# -----------------------
#  Включение основного сайта
# -----------------------

echo "Включение основного конфига..."

a2ensite "${SITENAME}.freedomvibe.net.conf" >/dev/null
apache2ctl configtest
systemctl reload apache2

echo "✓ Apache перезагружен с основным конфигом"

# -----------------------
#  MySQL: создание БД и пользователя
# -----------------------

echo "Создание базы данных $DB_NAME и пользователя $DB_USER..."

SQL="
CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${PASSWORD}';
CREATE USER '${DB_USER}'@'%' IDENTIFIED BY '${PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
GRANT ALL PRIVILEGES ON \`${DB_NAME}_%\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}_%\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
"

printf '%s' "$SQL" | mysql_exec

echo "✓ База данных и пользователь созданы"

# -----------------------
#  Создание файлов в webroot
# -----------------------

echo "Создание cred.txt и index.php..."

sudo -u "$SITENAME" bash -c "printf 'USER: %s\nPASS: %s\n' \"$DB_USER\" \"$PASSWORD\" > \"$WEBROOT/cred.txt\""
chmod 600 "$WEBROOT/cred.txt"
chown "$SITENAME":www-data "$WEBROOT/cred.txt"

sudo -u "$SITENAME" bash -c "cat > \"$WEBROOT/index.php\" <<'PHP'
<?php
echo 'Test';
PHP"

chown "$SITENAME":www-data "$WEBROOT/index.php"
chmod 664 "$WEBROOT/index.php"

echo "✓ Файлы созданы"

# -----------------------
#  Создание master cred файла
# -----------------------

echo "Создание файла с учётными данными..."

cat > "$CRED_MASTER" <<EOF
SITE: $DOMAIN
USER (system+ftp+db): $SITENAME
PASSWORD: $PASSWORD
WEBROOT: $WEBROOT
APACHE_CONF: $APACHE_CONF
DATABASE: $DB_NAME
EOF

chown root:root "$CRED_MASTER"
chmod 600 "$CRED_MASTER"

echo "✓ Файл с учётными данными создан"

# Отключаем trap после успешного завершения
trap - ERR

# -----------------------
#  Финальное сообщение
# -----------------------

echo ""
echo "=========================================="
echo "✓ Сайт успешно создан!"
echo ""
echo "Домен: https://$DOMAIN"
echo "Файл с учётными данными (root only):"
echo "$CRED_MASTER"
echo ""
echo "Для проверки запустите:"
echo "sudo bash check.sh $SITENAME"
echo "=========================================="