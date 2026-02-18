# Схема базы данных

## Две базы данных

Проект использует две отдельные базы данных для чистоты:

| База | Назначение |
|------|-----------|
| `telegram_parser` | Наши данные: товары, объявления, пользователи |
| `telegram_session` | Внутренние данные MadelineProto (управляется автоматически) |

---

## Таблицы `telegram_parser`

### `tg_users` — Пользователи Telegram

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | bigint PK | |
| `tg_id` | bigint UNIQUE | ID пользователя в Telegram |
| `username` | varchar(100) NULL | Ник (@username) |
| `display_name` | varchar(255) NULL | Отображаемое имя |
| `first_name` | varchar(255) NULL | Имя |
| `last_name` | varchar(255) NULL | Фамилия |
| `created_at` / `updated_at` | timestamp | |

**Ссылка на профиль:** `https://t.me/{username}` — только если есть username.

---

### `products` — Справочник товаров

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | bigint PK | |
| `parent_id` | bigint FK NULL | ID основного товара (для алиасов) |
| `icon` | varchar(50) NULL | Эмодзи иконка (например `🔖` или `🌡🎆`) |
| `name` | varchar(500) | Оригинальное название |
| `normalized_name` | varchar(500) | Нормализованное для поиска (lowercase, без эмодзи) |
| `status` | enum | `ok` / `needs_merge` |
| `created_at` / `updated_at` | timestamp | |

**Логика алиасов:**
- `parent_id = NULL` — основная запись товара
- `parent_id = N` — алиас, синоним основной записи с ID=N
- При запросах цен используем `COALESCE(parent_id, id)` как effective_id

**Логика иконок:**
- Иконка сохраняется из первого найденного объявления
- Если в новом объявлении нет иконки, а в БД есть — оставляем из БД
- Если в новом объявлении есть иконка, а в БД нет — обновляем

---

### `services` — Справочник услуг

Аналогична `products`, но для услуг и найма (не смешивается с товарами).

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | bigint PK | |
| `parent_id` | bigint FK NULL | |
| `icon` | varchar(50) NULL | |
| `name` | varchar(500) | |
| `normalized_name` | varchar(500) | |
| `status` | enum | `ok` / `needs_merge` |

---

### `tg_messages` — Сырые сообщения

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | bigint PK | |
| `tg_message_id` | bigint | ID сообщения в Telegram |
| `tg_chat_id` | bigint | ID чата в Telegram |
| `tg_user_id` | bigint FK NULL | Автор сообщения |
| `raw_text` | text | Полный оригинальный текст |
| `tg_link` | varchar(500) NULL | Ссылка: `https://t.me/chatname/message_id` |
| `sent_at` | timestamp | Время отправки в Telegram |
| `is_parsed` | boolean | Было ли обработано парсером |

**Уникальный ключ:** `(tg_message_id, tg_chat_id)`

---

### `listings` — Объявления купли/продажи товаров

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | bigint PK | |
| `tg_message_id` | bigint FK | Исходное сообщение |
| `tg_user_id` | bigint FK NULL | Автор объявления |
| `product_id` | bigint FK | Товар |
| `type` | enum | `buy` (куплю) / `sell` (продам) |
| `price` | bigint NULL | Цена (null если не указана) |
| `currency` | enum | `gold` 💰 / `cookie` 🍪 |
| `quantity` | int NULL | Количество товара |
| `posted_at` | timestamp | Дата объявления (из tg_messages) |
| `status` | enum | `ok` / `suspicious` / `needs_review` / `invalid` |
| `anomaly_reason` | varchar(500) NULL | Описание аномалии |

**Статусы:**
- `ok` — всё нормально
- `suspicious` — цена отклоняется от среднего более чем на `PRICE_ANOMALY_THRESHOLD`%
- `needs_review` — требует ручной проверки
- `invalid` — ошибка парсинга, не учитывается в статистике

---

### `service_listings` — Объявления услуг и найма

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | bigint PK | |
| `tg_message_id` | bigint FK | |
| `tg_user_id` | bigint FK NULL | |
| `service_id` | bigint FK NULL | |
| `type` | enum | `offer` (предлагаю) / `wanted` (ищу/найму) |
| `price` | bigint NULL | |
| `currency` | enum | `gold` / `cookie` |
| `description` | text NULL | Описание из объявления |
| `posted_at` | timestamp | |
| `status` | enum | `ok` / `suspicious` / `needs_review` / `invalid` |

---

### `exchanges` — Объявления обмена

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | bigint PK | |
| `tg_message_id` | bigint FK | |
| `tg_user_id` | bigint FK NULL | |
| `product_id` | bigint FK | Что отдаю |
| `product_quantity` | int | Сколько отдаю (default: 1) |
| `exchange_product_id` | bigint FK | Что хочу получить |
| `exchange_product_quantity` | int | Сколько хочу получить (default: 1) |
| `surcharge_amount` | bigint NULL | Сумма доплаты (null = чистый обмен) |
| `surcharge_currency` | enum NULL | Валюта доплаты: `gold` / `cookie` |
| `surcharge_direction` | enum NULL | `me` (я доплачиваю) / `them` (они доплачивают) |
| `posted_at` | timestamp | |

---

## Таблицы `telegram_session`

Создаются и управляются автоматически библиотекой MadelineProto через `danog/AsyncOrm`. Не трогать вручную.

---

## Полезные запросы

### Текущие цены (максимальная покупка / минимальная продажа)

```sql
SELECT
    COALESCE(p.parent_id, p.id) AS product_id,
    CONCAT(p.icon, ' ', p.name) AS product,
    MAX(CASE WHEN l.type = 'buy' THEN l.price END) AS max_buy,
    MIN(CASE WHEN l.type = 'sell' THEN l.price END) AS min_sell
FROM listings l
JOIN products p ON l.product_id = p.id
WHERE l.currency = 'gold'
  AND l.status != 'invalid'
  AND l.posted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY COALESCE(p.parent_id, p.id), p.icon, p.name
ORDER BY p.name;
```

### Аномальные записи

```sql
SELECT l.*, p.name, u.username
FROM listings l
JOIN products p ON l.product_id = p.id
LEFT JOIN tg_users u ON l.tg_user_id = u.id
WHERE l.status = 'suspicious'
ORDER BY l.posted_at DESC;
```
