# Проставляем normalized_title
UPDATE items
SET normalized_title = LOWER(TRIM(REGEXP_REPLACE(
    REGEXP_REPLACE(title, '\\s*\\[[IVX+]+\\]\\s*', ''),  -- сначала убираем [I], [II], [III+]
    '[^\\u0400-\\u04FF0-9a-zA-Z%+\\- ]', ''               -- потом убираем эмодзи
                                  )))
WHERE title IS NOT NULL;


