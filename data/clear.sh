#!/bin/bash
# delete_site.sh - Полная очистка сайта
# Usage: sudo bash clear.sh <sitename> [-y]
# -y: удалить без подтверждения

set -euo pipefail
IFS=$'\n\t'

# -----------------------
#  Параметры
# -----------------------

if [ -z "${1:-}" ]; then
  echo "Использование: $0 <sitename> [-y]"
  echo "  -y: удалить без подтверждения"
  exit 1
fi

SITENAME="$1"
FORCE_YES=false

# Проверка флага -y
if [ "${2:-}" = "-y" ]; then
  FORCE_YES=true
fi

DOMAIN="$SITENAME.freedomvibe.net"
WEBROOT="/var/www/$SITENAME"
APACHE_CONF="/etc/apache2/sites-available/${SITENAME}.freedomvibe.net.conf"
APACHE_TEMP_CONF="/etc/apache2/sites-available/${SITENAME}_temp.conf"
CRED_MASTER="/var/www/cred_${SITENAME}.txt"
DB_NAME="$SITENAME"
DB_USER="$SITENAME"  # ← ДОБАВЛЕНО!

# -----------------------
#  Проверка root
# -----------------------

if [ "$EUID" -ne 0 ]; then
  echo "Ошибка: Скрипт требует root-привилегий."
  exit 1
fi

# -----------------------
#  Определение метода подключения к MySQL
# -----------------------
MYSQL_OPTS=""
if [ -f /etc/mysql/debian.cnf ] && mysql --defaults-file=/etc/mysql/debian.cnf -e "SELECT 1" >/dev/null 2>&1; then
  MYSQL_OPTS="--defaults-file=/etc/mysql/debian.cnf"
elif mysql -e "SELECT 1" >/dev/null 2>&1; then
  MYSQL_OPTS=""
elif mysql -u root -e "SELECT 1" >/dev/null 2>&1; then
  MYSQL_OPTS="-u root"
else
  echo "Предупреждение: Не удалось подключиться к MySQL — проверки и удаление БД будут пропущены"
fi

# -----------------------
#  Функция подтверждения
# -----------------------

confirm() {
  local message="$1"
  
  if [ "$FORCE_YES" = true ]; then
    echo "$message [автоматическое подтверждение: -y]"
    return 0
  fi
  
  while true; do
    read -p "$message (y/n): " yn
    case $yn in
      [Yy]* ) return 0;;
      [Nn]* ) return 1;;
      * ) echo "Ответьте y (да) или n (нет).";;
    esac
  done
}

# -----------------------
#  Начало удаления
# -----------------------

echo "=========================================="
echo "Удаление сайта: $SITENAME"
echo "=========================================="
echo ""

FOUND_SOMETHING=false

# -----------------------
#  1. Проверка webroot
# -----------------------

if [ -d "$WEBROOT" ]; then
  FOUND_SOMETHING=true
  
  # Проверяем пустой ли каталог
  if [ "$(ls -A "$WEBROOT" 2>/dev/null)" ]; then
    echo "⚠ Каталог $WEBROOT не пуст:"
    ls -lah "$WEBROOT" | head -n 10
    echo ""
    
    if confirm "Удалить все файлы из $WEBROOT?"; then
      echo "Удаление $WEBROOT..."
      rm -rf "$WEBROOT"
      echo "✓ Каталог удалён"
    else
      echo "⊘ Пропущено удаление webroot"
    fi
  else
    echo "Удаление пустого каталога $WEBROOT..."
    rmdir "$WEBROOT"
    echo "✓ Каталог удалён"
  fi
else
  echo "○ Каталог $WEBROOT не существует"
fi

echo ""

# -----------------------
#  2. Удаление пользователя
# -----------------------

if id -u "$SITENAME" >/dev/null 2>&1; then
  FOUND_SOMETHING=true
  echo "Удаление пользователя $SITENAME..."
  
  # Проверяем домашнюю директорию
  USER_HOME=$(eval echo ~"$SITENAME")
  if [ -d "$USER_HOME" ] && [ "$USER_HOME" != "$WEBROOT" ]; then
    echo "⚠ У пользователя есть домашняя директория: $USER_HOME"
    if confirm "Удалить домашнюю директорию пользователя?"; then
      userdel -r "$SITENAME" 2>/dev/null || userdel "$SITENAME"
    else
      userdel "$SITENAME"
    fi
  else
    userdel "$SITENAME" 2>/dev/null || true
  fi
  
  echo "✓ Пользователь удалён"
else
  echo "○ Пользователь $SITENAME не существует"
fi

echo ""

# -----------------------
#  3. Удаление Apache конфигов
# -----------------------

APACHE_CONFIGS_FOUND=false

# Основной конфиг
if [ -f "$APACHE_CONF" ]; then
  APACHE_CONFIGS_FOUND=true
  echo "Отключение и удаление $APACHE_CONF..."
  a2dissite "$(basename "$APACHE_CONF")" 2>/dev/null || true
  rm -f "$APACHE_CONF"
  echo "✓ Конфиг удалён"
fi

# Временный конфиг
if [ -f "$APACHE_TEMP_CONF" ]; then
  APACHE_CONFIGS_FOUND=true
  echo "Отключение и удаление $APACHE_TEMP_CONF..."
  a2dissite "$(basename "$APACHE_TEMP_CONF")" 2>/dev/null || true
  rm -f "$APACHE_TEMP_CONF"
  echo "✓ Временный конфиг удалён"
fi

# SSL конфиг (если certbot создал)
SSL_CONF="/etc/apache2/sites-available/${SITENAME}.freedomvibe.net-le-ssl.conf"
if [ -f "$SSL_CONF" ]; then
  APACHE_CONFIGS_FOUND=true
  echo "Отключение и удаление $SSL_CONF..."
  a2dissite "$(basename "$SSL_CONF")" 2>/dev/null || true
  rm -f "$SSL_CONF"
  echo "✓ SSL конфиг удалён"
fi

if [ "$APACHE_CONFIGS_FOUND" = true ]; then
  FOUND_SOMETHING=true
  echo "Перезагрузка Apache..."
  
  # Проверяем конфигурацию перед перезагрузкой
  if apache2ctl configtest 2>&1 | grep -q "Syntax OK"; then
    systemctl reload apache2 && echo "✓ Apache перезагружен" || echo "⚠ Ошибка перезагрузки Apache"
  else
    echo "⚠ Ошибка в конфигурации Apache, пропускаем перезагрузку"
    echo "Запустите: apache2ctl configtest"
  fi
else
  echo "○ Apache конфиги не найдены"
fi

echo ""

# -----------------------
#  4. Удаление базы данных
# -----------------------

DB_EXISTS=false
# Используем sudo для проверки
if mysql $MYSQL_OPTS -e "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$DB_NAME'" 2>/dev/null | grep -q "$DB_NAME"; then
  DB_EXISTS=true
fi

if [ "$DB_EXISTS" = true ]; then
  FOUND_SOMETHING=true
  
  # Показываем список таблиц
  echo "⚠ База данных $DB_NAME существует"
  TABLE_COUNT=$(mysql $MYSQL_OPTS -e "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '$DB_NAME'" 2>/dev/null | tail -n 1)
  echo "Таблиц в базе: $TABLE_COUNT"
  
  # Спрашиваем подтверждение только если есть таблицы
  SHOULD_DELETE=false
  if [ "$TABLE_COUNT" -gt 0 ]; then
    if confirm "База содержит таблицы. Удалить базу данных $DB_NAME и пользователя?"; then
      SHOULD_DELETE=true
    fi
  else
    echo "База данных пуста, удаляем без подтверждения..."
    SHOULD_DELETE=true
  fi
  
  if [ "$SHOULD_DELETE" = true ]; then
    echo "Удаление базы данных и пользователя..."
    
    # ИСПРАВЛЕНО: используем sudo и правильную переменную
    mysql $MYSQL_OPTS <<SQL 2>/dev/null || echo "⚠ Ошибка при удалении БД"
DROP DATABASE IF EXISTS \`$DB_NAME\`;
DROP USER IF EXISTS '$DB_USER'@'localhost';
DROP USER IF EXISTS '$DB_USER'@'%';
FLUSH PRIVILEGES;
SQL
    
    echo "✓ База данных и пользователь удалены"
  else
    echo "⊘ Пропущено удаление базы данных"
  fi
else
  echo "○ База данных $DB_NAME не существует"
fi

echo ""

# -----------------------
#  5. Удаление SSL сертификатов
# -----------------------

CERT_PATH="/etc/letsencrypt/live/$DOMAIN"
if [ -d "$CERT_PATH" ]; then
  FOUND_SOMETHING=true
  echo "⚠ SSL сертификаты найдены: $CERT_PATH"
  
  if confirm "Удалить SSL сертификаты для $DOMAIN?"; then
    echo "Удаление сертификатов через certbot..."
    certbot delete --cert-name "$DOMAIN" --non-interactive 2>/dev/null || {
      echo "⚠ Certbot не смог удалить, удаляем вручную..."
      rm -rf "$CERT_PATH"
      rm -rf "/etc/letsencrypt/archive/$DOMAIN"
      rm -rf "/etc/letsencrypt/renewal/$DOMAIN.conf"
    }
    echo "✓ Сертификаты удалены"
  else
    echo "⊘ Пропущено удаление сертификатов"
  fi
else
  echo "○ SSL сертификаты не найдены"
fi

echo ""

# -----------------------
#  6. Удаление файла с кредами
# -----------------------

if [ -f "$CRED_MASTER" ]; then
  FOUND_SOMETHING=true
  echo "Удаление файла с учётными данными $CRED_MASTER..."
  rm -f "$CRED_MASTER"
  echo "✓ Файл удалён"
else
  echo "○ Файл с кредами не найден"
fi

echo ""

# -----------------------
#  7. Очистка логов Apache (опционально)
# -----------------------

DEBUG_LOGS=(/var/log/apache2/${SITENAME}_debug*.log)
if [ -e "${DEBUG_LOGS[0]}" ]; then
  FOUND_SOMETHING=true
  
  if confirm "Удалить debug логи Apache?"; then
    echo "Удаление debug логов..."
    rm -f "/var/log/apache2/${SITENAME}_debug*.log"
    echo "✓ Debug логи удалены"
  else
    echo "⊘ Пропущено удаление debug логов"
  fi
fi

# -----------------------
#  Итоги
# -----------------------

echo ""
echo "=========================================="

if [ "$FOUND_SOMETHING" = false ]; then
  echo "○ Сайт $SITENAME не был найден"
  echo "Нечего удалять."
else
  echo "✓ Очистка завершена"
fi

echo "=========================================="