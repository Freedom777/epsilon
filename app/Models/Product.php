<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class Product extends Model
{
    protected $fillable = [
        'parent_id',
        'icon',
        'name',
        'normalized_name',
        'grade',
        'type',
        'status',
        'is_verified',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
    ];

    // =========================================================================
    // Связи
    // =========================================================================

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(Product::class, 'parent_id');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function pendingReviews(): HasMany
    {
        return $this->hasMany(ProductPending::class);
    }

    // =========================================================================
    // Аксессоры
    // =========================================================================

    /**
     * Эффективный ID: если это алиас — возвращаем parent_id, иначе свой id.
     */
    public function getEffectiveIdAttribute(): int
    {
        return $this->parent_id ?? $this->id;
    }

    /**
     * Отображаемое название с иконкой и грейдом.
     */
    public function getFullNameAttribute(): string
    {
        $parts = [];
        if ($this->icon) {
            $parts[] = $this->icon;
        }
        $parts[] = $this->name;
        if ($this->grade) {
            $parts[] = '[' . $this->grade . ']';
        }
        return implode(' ', $parts);
    }

    // =========================================================================
    // Нормализация
    // =========================================================================

    public static function normalizeName(string $name): string
    {
        // Убираем эмодзи
        $name = preg_replace('/[\x{1F000}-\x{1FFFF}]|[\x{2600}-\x{27FF}]|[\x{2300}-\x{23FF}]/u', '', $name);
        // Убираем лишние пробелы
        $name = trim(preg_replace('/\s+/', ' ', $name));
        return mb_strtolower($name);
    }

    // =========================================================================
    // Поиск / создание / очередь
    // =========================================================================

    /**
     * Найти товар по имени и грейду.
     * Трёхуровневый матчинг:
     *   1. Точное совпадение normalized_name + grade
     *   2. Совпадение по префиксу (первые N символов) + grade
     *   3. Не нашли → null (вызывающий код кладёт в products_pending)
     *
     * При нахождении — обновляем icon и grade если в БД пустые.
     */
    public static function findByNameAndGrade(
        string $rawName,
        ?string $grade,
        ?string $icon = null
    ): ?self {
        $normalized   = static::normalizeName($rawName);
        $prefixLength = (int) config('parser.product_match_prefix_length', 15);

        // Уровень 1: точное совпадение
        $product = static::where('normalized_name', $normalized)
            ->where('grade', $grade)
            ->first();

        // Уровень 2: по префиксу
        if (!$product && mb_strlen($normalized) >= $prefixLength) {
            $prefix     = mb_substr($normalized, 0, $prefixLength);
            $candidates = static::where('normalized_name', 'LIKE', $prefix . '%')
                ->where('grade', $grade)
                ->get();

            if ($candidates->count() === 1) {
                // Один кандидат — берём его
                $product = $candidates->first();
            } elseif ($candidates->count() > 1) {
                // Несколько — выбираем с наибольшим совпадением
                $product = static::bestMatch($normalized, $candidates);
            }
        }

        // Нашли — обновляем пустые поля
        if ($product) {
            $updates = [];
            if (!$product->icon  && $icon)  $updates['icon']  = $icon;
            if (!$product->grade && $grade) $updates['grade'] = $grade;
            if (!empty($updates)) {
                $product->update($updates);
                $product->refresh();
            }
        }

        return $product;
    }

    /**
     * Основной метод для парсера:
     * - Ищет товар через findByNameAndGrade()
     * - Если не нашёл — кладёт в products_pending и возвращает null
     * - Если нашёл, но есть конфликт icon/grade — тоже пишет в products_pending
     */
    public static function findOrQueue(
        string $rawName,
        ?string $grade,
        ?string $icon,
        ?int $tgMessageId
    ): ?self {
        $normalized = static::normalizeName($rawName);
        $product    = static::findByNameAndGrade($rawName, $grade, $icon);

        if (!$product) {
            // Уровень 3: не нашли — в очередь
            static::queuePending([
                'product_id'      => null,
                'icon'            => $icon,
                'name'            => $rawName,
                'normalized_name' => $normalized,
                'grade'           => $grade,
                'status'          => 'new',
                'tg_message_id'   => $tgMessageId,
            ]);
            return null;
        }

        // Проверяем конфликты icon и grade
        static::checkAndQueueConflicts($product, $icon, $grade, $tgMessageId);

        // Проверяем отсутствующие поля
        static::checkAndQueueMissing($product, $tgMessageId);

        return $product;
    }

    // =========================================================================
    // Приватные хелперы
    // =========================================================================

    /**
     * Выбираем кандидата с наибольшим совпадением с $normalized.
     * Используем похожесть по similar_text().
     */
    private static function bestMatch(string $normalized, $candidates): ?self
    {
        $best      = null;
        $bestScore = 0;

        foreach ($candidates as $candidate) {
            similar_text($normalized, $candidate->normalized_name, $percent);
            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best      = $candidate;
            }
        }

        // Принимаем только если совпадение достаточно высокое
        return ($bestScore >= 70) ? $best : null;
    }

    /**
     * Проверить конфликты icon и grade.
     * Конфликт = в БД уже есть значение И оно отличается от того что пришло.
     */
    private static function checkAndQueueConflicts(
        self $product,
        ?string $icon,
        ?string $grade,
        ?int $tgMessageId
    ): void {
        if ($icon && $product->icon && $product->icon !== $icon) {
            static::queuePending([
                'product_id'      => $product->id,
                'icon'            => $icon,
                'name'            => $product->name,
                'normalized_name' => $product->normalized_name,
                'grade'           => $grade,
                'status'          => 'icon_conflict',
                'tg_message_id'   => $tgMessageId,
            ]);
        }

        if ($grade && $product->grade && $product->grade !== $grade) {
            static::queuePending([
                'product_id'      => $product->id,
                'icon'            => $icon,
                'name'            => $product->name,
                'normalized_name' => $product->normalized_name,
                'grade'           => $grade,
                'status'          => 'grade_conflict',
                'tg_message_id'   => $tgMessageId,
            ]);
        }
    }

    /**
     * Проверить отсутствующие поля icon и grade.
     */
    private static function checkAndQueueMissing(self $product, ?int $tgMessageId): void
    {
        if (!$product->icon) {
            static::queuePending([
                'product_id'      => $product->id,
                'icon'            => null,
                'name'            => $product->name,
                'normalized_name' => $product->normalized_name,
                'grade'           => $product->grade,
                'status'          => 'missing_icon',
                'tg_message_id'   => $tgMessageId,
            ]);
        }

        // missing_grade — только если у товара тип предполагает грейд
        // (weapon, armor, jewelry, scroll, recipe) и грейда нет
        $typesWithGrade = ['weapon', 'armor', 'jewelry', 'scroll', 'recipe'];
        if (!$product->grade && in_array($product->type, $typesWithGrade)) {
            static::queuePending([
                'product_id'      => $product->id,
                'icon'            => $product->icon,
                'name'            => $product->name,
                'normalized_name' => $product->normalized_name,
                'grade'           => null,
                'status'          => 'missing_grade',
                'tg_message_id'   => $tgMessageId,
            ]);
        }
    }

    /**
     * Записать в products_pending.
     * Не создаём дубли: одна запись на (normalized_name + grade + status).
     */
    private static function queuePending(array $data): void
    {
        try {
            ProductPending::firstOrCreate(
                [
                    'normalized_name' => $data['normalized_name'],
                    'grade'           => $data['grade'],
                    'status'          => $data['status'],
                    'reviewed'        => false,
                ],
                $data
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to queue product pending', ['data' => $data, 'error' => $e->getMessage()]);
        }
    }
}
