<?php

namespace App\Services;

use App\Models\Listing;

class PriceAnomalyDetector
{
    private int $thresholdPercent;
    private int $days;
    private int $minSamples;

    public function __construct()
    {
        $this->thresholdPercent = config('parser.anomaly.threshold', 50);
        $this->days             = config('parser.anomaly.days', 7);
        $this->minSamples       = config('parser.anomaly.min_samples', 3);
    }

    /**
     * Проверить цену листинга на аномальность.
     * Возвращает массив ['status' => ..., 'reason' => ...] или null если нет данных.
     *
     * @param  int     $productId  ID товара (основного, с учётом parent_id)
     * @param  string  $type       'buy' | 'sell'
     * @param  string  $currency   'gold' | 'cookie'
     * @param  int     $price      Цена для проверки
     * @return array
     */
    public function check(int $productId, string $type, string $currency, int $price): array
    {
        $average = $this->getAveragePrice($productId, $type, $currency);

        if ($average === null) {
            // Недостаточно данных — не можем определить аномалию
            return ['status' => 'ok', 'reason' => null];
        }

        $deviation = abs($price - $average) / $average * 100;

        if ($deviation > $this->thresholdPercent) {
            $direction = $price > $average ? 'выше' : 'ниже';
            $reason    = sprintf(
                'Цена %d %s на %.1f%% %s среднего %d за %d дней',
                $price,
                $currency === 'gold' ? '💰' : '🍪',
                $deviation,
                $direction,
                (int) $average,
                $this->days
            );

            return ['status' => 'suspicious', 'reason' => $reason];
        }

        return ['status' => 'ok', 'reason' => null];
    }

    /**
     * Получить среднюю цену товара за последние N дней.
     * Возвращает null если записей меньше minSamples.
     */
    private function getAveragePrice(int $productId, string $type, string $currency): ?float
    {
        $rows = Listing::query()
            ->where(function ($q) use ($productId) {
                $q->where('product_id', $productId)
                  ->orWhereHas('product', fn($pq) => $pq->where('parent_id', $productId));
            })
            ->where('type', $type)
            ->where('currency', $currency)
            ->where('status', '!=', 'invalid')
            ->whereNotNull('price')
            ->where('posted_at', '>=', now()->subDays($this->days))
            ->pluck('price');

        if ($rows->count() < $this->minSamples) {
            return null;
        }

        return $rows->average();
    }
}
