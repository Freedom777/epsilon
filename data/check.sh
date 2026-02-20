#!/bin/bash
# check_site.sh - Проверка корректности созданного сайта
# Usage: sudo bash check.sh <sitename>

# УБРАЛИ set -e чтобы скрипт продолжал работу при ошибках проверок
set -uo pipefail
IFS=$'\n\t'

# -----------------------
#  Параметры
# -----------------------

if [ -z "${1:-}" ]; then
  echo "Использование: $0 <sitename>"
  exit 1
fi

SITENAME="$1"
DOMAIN="$SITENAME.freedomvibe.net"
WWWDOMAIN="www.$DOMAIN"
WEBROOT="/var/www/$SITENAME"
APACHE_CONF="/etc/apache2/sites-available/${SITENAME}.freedomvibe.net.conf"
CRED_MASTER="/var/www/cred_${SITENAME}.txt"
DB_NAME="$SITENAME"
DB_USER="$SITENAME"
SITELOG="$WEBROOT/sitelog"

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Счётчики
PASSED=0
FAILED=0
WARNINGS=0

# -----------------------
#  Функции проверки
# -----------------------

check_pass() {
  echo -e "${GREEN}✓${NC} $1"
  ((PASSED++))
}

check_fail() {
  echo -e "${RED}✗${NC} $1"
  ((FAILED++))
}

check_warn() {
  echo -e "${YELLOW}⚠${NC} $1"
  ((WARNINGS++))
}

# -----------------------
#  Проверка root
# -----------------------

if [ "$EUID" -ne 0 ]; then
  echo "Предупреждение: Некоторые проверки требуют root-привилегий."
  echo "Рекомендуется запустить с sudo."
  echo ""
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
  echo "Предупреждение: Не удалось подключиться к MySQL — проверки БД будут пропущены"
fi

# -----------------------
#  Заголовок
# -----------------------

echo "=========================================="
echo "Проверка сайта: $SITENAME"
echo "Домен: $DOMAIN"
echo "=========================================="
echo ""

# -----------------------
#  1. DNS проверка
# -----------------------

echo "1. Проверка DNS записей"
echo "----------------------------------------"

# Функция резолва
resolve_domain() {
  if command -v dig >/dev/null 2>&1; then
    dig +short A "$1" 2>/dev/null | head -n1
  elif command -v getent >/dev/null 2>&1; then
    getent hosts "$1" 2>/dev/null | awk '{print $1}' | head -n1
  else
    echo ""
  fi
}

DOMAIN_IP=$(resolve_domain "$DOMAIN")
WWWDOMAIN_IP=$(resolve_domain "$WWWDOMAIN")
SERVER_IP=$(hostname -I | awk '{print $1}')

if [ -n "$DOMAIN_IP" ]; then
  if [ "$DOMAIN_IP" = "$SERVER_IP" ]; then
    check_pass "DNS A-запись $DOMAIN → $DOMAIN_IP (совпадает с сервером)"
  else
    check_warn "DNS A-запись $DOMAIN → $DOMAIN_IP (сервер: $SERVER_IP) - не совпадает!"
  fi
else
  check_fail "DNS A-запись для $DOMAIN не найдена"
fi

if [ -n "$WWWDOMAIN_IP" ]; then
  if [ "$WWWDOMAIN_IP" = "$SERVER_IP" ]; then
    check_pass "DNS A-запись $WWWDOMAIN → $WWWDOMAIN_IP"
  else
    check_warn "DNS A-запись $WWWDOMAIN → $WWWDOMAIN_IP (сервер: $SERVER_IP) - не совпадает!"
  fi
else
  check_fail "DNS A-запись для $WWWDOMAIN не найдена"
fi

echo ""

# -----------------------
#  2. Системный пользователь
# -----------------------

echo "2. Проверка системного пользователя"
echo "----------------------------------------"

# Используем || true чтобы не падать при ошибке
if id -u "$SITENAME" >/dev/null 2>&1; then
  check_pass "Пользователь $SITENAME существует"
  
  USER_GROUP=$(id -gn "$SITENAME" 2>/dev/null || echo "unknown")
  if [ "$USER_GROUP" = "www-data" ]; then
    check_pass "Пользователь в группе www-data"
  else
    check_fail "Пользователь НЕ в группе www-data (текущая: $USER_GROUP)"
  fi
  
  USER_HOME=$(eval echo ~"$SITENAME" 2>/dev/null || echo "unknown")
  if [ "$USER_HOME" = "$WEBROOT" ]; then
    check_pass "Домашняя директория: $WEBROOT"
  else
    check_warn "Домашняя директория: $USER_HOME (ожидалось: $WEBROOT)"
  fi
else
  check_fail "Пользователь $SITENAME не существует"
fi

echo ""

# -----------------------
#  3. Webroot и права
# -----------------------

echo "3. Проверка webroot и прав доступа"
echo "----------------------------------------"

if [ -d "$WEBROOT" ]; then
  check_pass "Каталог $WEBROOT существует"
  
  WEBROOT_OWNER=$(stat -c '%U:%G' "$WEBROOT" 2>/dev/null || echo "unknown")
  if [ "$WEBROOT_OWNER" = "$SITENAME:www-data" ]; then
    check_pass "Владелец: $WEBROOT_OWNER"
  else
    check_fail "Владелец: $WEBROOT_OWNER (ожидалось: $SITENAME:www-data)"
  fi
  
  WEBROOT_PERMS=$(stat -c '%a' "$WEBROOT" 2>/dev/null || echo "000")
  if [[ "$WEBROOT_PERMS" =~ ^27[75][75]$ ]]; then
    check_pass "Права: $WEBROOT_PERMS (setgid установлен)"
  else
    check_warn "Права: $WEBROOT_PERMS (рекомендуется: 2775)"
  fi
  
  # index.php
  if [ -f "$WEBROOT/index.php" ]; then
    check_pass "Файл index.php существует"
    
    INDEX_OWNER=$(stat -c '%U:%G' "$WEBROOT/index.php" 2>/dev/null || echo "unknown")
    if [ "$INDEX_OWNER" = "$SITENAME:www-data" ]; then
      check_pass "index.php владелец: $INDEX_OWNER"
    else
      check_warn "index.php владелец: $INDEX_OWNER (ожидалось: $SITENAME:www-data)"
    fi
  else
    check_warn "Файл index.php не найден"
  fi
  
  # cred.txt
  if [ -f "$WEBROOT/cred.txt" ]; then
    check_pass "Файл cred.txt существует"
    
    CRED_PERMS=$(stat -c '%a' "$WEBROOT/cred.txt" 2>/dev/null || echo "000")
    if [ "$CRED_PERMS" = "600" ]; then
      check_pass "cred.txt права: $CRED_PERMS"
    else
      check_warn "cred.txt права: $CRED_PERMS (рекомендуется: 600)"
    fi
  else
    check_fail "Файл cred.txt не найден"
  fi
else
  check_fail "Каталог $WEBROOT не существует"
fi

echo ""

# -----------------------
#  4. Sitelog
# -----------------------

echo "4. Проверка каталога логов"
echo "----------------------------------------"

if [ -d "$SITELOG" ]; then
  check_pass "Каталог $SITELOG существует"
  
  SITELOG_OWNER=$(stat -c '%U:%G' "$SITELOG" 2>/dev/null || echo "unknown")
  if [ "$SITELOG_OWNER" = "www-data:root" ]; then
    check_pass "Владелец sitelog: $SITELOG_OWNER"
  else
    check_warn "Владелец sitelog: $SITELOG_OWNER (ожидалось: www-data:root)"
  fi
  
  SITELOG_PERMS=$(stat -c '%a' "$SITELOG" 2>/dev/null || echo "000")
  if [ "$SITELOG_PERMS" = "750" ]; then
    check_pass "Права sitelog: $SITELOG_PERMS"
  else
    check_warn "Права sitelog: $SITELOG_PERMS (ожидалось: 750)"
  fi
  
  # Проверка ACL
  if command -v getfacl >/dev/null 2>&1; then
    ACL_OUTPUT=$(getfacl "$SITELOG" 2>/dev/null | grep "user:$SITENAME" || true)
    if [ -n "$ACL_OUTPUT" ]; then
      check_pass "ACL для пользователя $SITENAME настроены"
    else
      check_warn "ACL для пользователя $SITENAME не найдены"
    fi
  fi
else
  check_fail "Каталог $SITELOG не существует"
fi

echo ""

# -----------------------
#  5. Apache конфигурация
# -----------------------

echo "5. Проверка Apache конфигурации"
echo "----------------------------------------"

if [ -f "$APACHE_CONF" ]; then
  check_pass "Конфиг $APACHE_CONF существует"
  
  # Проверка включен ли сайт
  if [ -L "/etc/apache2/sites-enabled/$(basename "$APACHE_CONF")" ]; then
    check_pass "Сайт включен (a2ensite)"
  else
    check_fail "Сайт НЕ включен в sites-enabled"
  fi
  
  # Проверка синтаксиса
  if apache2ctl configtest 2>&1 | grep -q "Syntax OK"; then
    check_pass "Синтаксис Apache конфигурации корректен"
  else
    check_fail "Ошибка в синтаксисе Apache конфигурации"
  fi
  
  # Проверка VirtualHost в списке
  if apache2ctl -S 2>/dev/null | grep -q "$DOMAIN"; then
    check_pass "VirtualHost $DOMAIN найден в apache2ctl -S"
  else
    check_fail "VirtualHost $DOMAIN НЕ найден в apache2ctl -S"
  fi
  
  # Проверка HTTP секции
  if grep -q "<VirtualHost \*:80>" "$APACHE_CONF"; then
    check_pass "HTTP VirtualHost (порт 80) настроен"
  else
    check_fail "HTTP VirtualHost не найден"
  fi
  
  # Проверка HTTPS секции
  if grep -q "<VirtualHost \*:443>" "$APACHE_CONF"; then
    check_pass "HTTPS VirtualHost (порт 443) настроен"
  else
    check_warn "HTTPS VirtualHost не найден (возможно SSL не настроен)"
  fi
  
  # Проверка редиректа
  if grep -q "RewriteRule.*https://" "$APACHE_CONF"; then
    check_pass "Редирект HTTP → HTTPS настроен"
  else
    check_warn "Редирект HTTP → HTTPS не найден"
  fi
else
  check_fail "Конфиг $APACHE_CONF не существует"
fi

echo ""

# -----------------------
#  6. SSL сертификаты
# -----------------------

echo "6. Проверка SSL сертификатов"
echo "----------------------------------------"

CERT_PATH="/etc/letsencrypt/live/$DOMAIN"

if [ -d "$CERT_PATH" ]; then
  check_pass "SSL сертификаты найдены в $CERT_PATH"
  
  # Проверка файлов
  if [ -f "$CERT_PATH/fullchain.pem" ]; then
    check_pass "fullchain.pem существует"
    
    # Проверка срока действия
    EXPIRY=$(openssl x509 -enddate -noout -in "$CERT_PATH/fullchain.pem" 2>/dev/null | cut -d= -f2 || echo "")
    if [ -n "$EXPIRY" ]; then
      EXPIRY_EPOCH=$(date -d "$EXPIRY" +%s 2>/dev/null || echo 0)
      NOW_EPOCH=$(date +%s)
      DAYS_LEFT=$(( ($EXPIRY_EPOCH - $NOW_EPOCH) / 86400 ))
      
      if [ $DAYS_LEFT -gt 30 ]; then
        check_pass "Сертификат действителен (осталось $DAYS_LEFT дней)"
      elif [ $DAYS_LEFT -gt 0 ]; then
        check_warn "Сертификат скоро истечёт (осталось $DAYS_LEFT дней)"
      else
        check_fail "Сертификат истёк!"
      fi
    else
      check_warn "Не удалось проверить срок действия сертификата"
    fi
  else
    check_fail "fullchain.pem не найден"
  fi
  
  if [ -f "$CERT_PATH/privkey.pem" ]; then
    check_pass "privkey.pem существует"
  else
    check_fail "privkey.pem не найден"
  fi
else
  check_warn "SSL сертификаты не найдены (возможно не настроены)"
fi

echo ""

# -----------------------
#  7. База данных
# -----------------------

echo "7. Проверка базы данных MySQL"
echo "----------------------------------------"

# Проверка существования БД
DB_CHECK=$(mysql $MYSQL_OPTS -e "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$DB_NAME'" 2>/dev/null | grep "$DB_NAME" || echo "")
if [ -n "$DB_CHECK" ]; then
  check_pass "База данных $DB_NAME существует"
  
  # Проверка кодировки
  CHARSET=$(mysql $MYSQL_OPTS -e "SELECT DEFAULT_CHARACTER_SET_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$DB_NAME'" 2>/dev/null | tail -n1 || echo "unknown")
  if [ "$CHARSET" = "utf8mb4" ]; then
    check_pass "Кодировка базы: utf8mb4"
  else
    check_warn "Кодировка базы: $CHARSET (рекомендуется: utf8mb4)"
  fi
  
  # Количество таблиц
  TABLE_COUNT=$(mysql $MYSQL_OPTS -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '$DB_NAME'" 2>/dev/null | tail -n1 || echo "0")
  echo "  Таблиц в базе: $TABLE_COUNT"
else
  check_fail "База данных $DB_NAME не существует"
fi

# Проверка пользователя БД localhost
USER_CHECK=$(mysql $MYSQL_OPTS -e "SELECT User FROM mysql.user WHERE User = '$DB_USER' AND Host = 'localhost'" 2>/dev/null | grep "$DB_USER" || echo "")
if [ -n "$USER_CHECK" ]; then
  check_pass "Пользователь MySQL $DB_USER@localhost существует"
  
  # Проверка прав
  GRANTS=$(mysql $MYSQL_OPTS -e "SHOW GRANTS FOR '$DB_USER'@'localhost'" 2>/dev/null | grep "$DB_NAME" || echo "")
  if [ -n "$GRANTS" ]; then
    check_pass "Права на базу $DB_NAME назначены"
  else
    check_fail "Права на базу $DB_NAME НЕ назначены"
  fi
else
  check_fail "Пользователь MySQL $DB_USER@localhost не существует"
fi

# Проверка пользователя БД удалённый доступ
USER_REMOTE_CHECK=$(mysql $MYSQL_OPTS -e "SELECT User FROM mysql.user WHERE User = '$DB_USER' AND Host = '%'" 2>/dev/null | grep "$DB_USER" || echo "")
if [ -n "$USER_REMOTE_CHECK" ]; then
  check_pass "Пользователь MySQL $DB_USER@% существует (удалённый доступ)"
else
  check_warn "Пользователь MySQL $DB_USER@% не найден (только локальный доступ)"
fi

echo ""

# -----------------------
#  8. Файл с учётными данными
# -----------------------

echo "8. Проверка файла с учётными данными"
echo "----------------------------------------"

if [ -f "$CRED_MASTER" ]; then
  check_pass "Файл $CRED_MASTER существует"
  
  CRED_OWNER=$(stat -c '%U:%G' "$CRED_MASTER" 2>/dev/null || echo "unknown")
  if [ "$CRED_OWNER" = "root:root" ]; then
    check_pass "Владелец: root:root"
  else
    check_warn "Владелец: $CRED_OWNER (ожидалось: root:root)"
  fi
  
  CRED_PERMS=$(stat -c '%a' "$CRED_MASTER" 2>/dev/null || echo "000")
  if [ "$CRED_PERMS" = "600" ]; then
    check_pass "Права: 600"
  else
    check_fail "Права: $CRED_PERMS (должно быть: 600)"
  fi
else
  check_fail "Файл $CRED_MASTER не существует"
fi

echo ""

# -----------------------
#  9. HTTP/HTTPS доступность
# -----------------------

echo "9. Проверка доступности сайта"
echo "----------------------------------------"

# HTTP
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://$DOMAIN/" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "301" ] || [ "$HTTP_CODE" = "302" ]; then
  check_pass "HTTP доступен (редирект $HTTP_CODE)"
elif [ "$HTTP_CODE" = "200" ]; then
  check_warn "HTTP доступен (200 OK) - но должен редиректить на HTTPS"
else
  check_fail "HTTP недоступен (код: $HTTP_CODE)"
fi

# HTTPS
HTTPS_CODE=$(curl -s -o /dev/null -w "%{http_code}" "https://$DOMAIN/" 2>/dev/null || echo "000")
if [ "$HTTPS_CODE" = "200" ]; then
  check_pass "HTTPS доступен (200 OK)"
elif [ "$HTTPS_CODE" = "000" ]; then
  check_warn "HTTPS недоступен (возможно SSL не настроен)"
else
  check_fail "HTTPS ошибка (код: $HTTPS_CODE)"
fi

# Проверка index.php
if [ "$HTTPS_CODE" = "200" ]; then
  CONTENT=$(curl -s "https://$DOMAIN/index.php" 2>/dev/null || echo "")
  if echo "$CONTENT" | grep -q "Test"; then
    check_pass "index.php отдаёт корректное содержимое"
  else
    check_warn "index.php не содержит ожидаемый текст 'Test'"
  fi
fi

echo ""

# -----------------------
#  Итоги
# -----------------------

echo "=========================================="
echo "Итоги проверки:"
echo "=========================================="
echo -e "${GREEN}Успешно:${NC} $PASSED"
echo -e "${YELLOW}Предупреждения:${NC} $WARNINGS"
echo -e "${RED}Ошибки:${NC} $FAILED"
echo "=========================================="
echo ""

if [ $FAILED -eq 0 ] && [ $WARNINGS -eq 0 ]; then
  echo -e "${GREEN}✓ Сайт настроен полностью корректно!${NC}"
  exit 0
elif [ $FAILED -eq 0 ]; then
  echo -e "${YELLOW}⚠ Сайт работает, но есть предупреждения${NC}"
  exit 0
else
  echo -e "${RED}✗ Обнаружены критические ошибки${NC}"
  exit 1
fi