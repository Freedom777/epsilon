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
     * @param  int     $id          asset_id или item_id
     * @param  string  $sourceType  'asset' | 'item'
     * @param  string  $type        'buy' | 'sell'
     * @param  string  $currency    'gold' | 'cookie'
     * @param  int     $price       Цена для проверки
     */
    public function check(int $id, string $sourceType, string $type, string $currency, int $price): array
    {
        $average = $this->getAveragePrice($id, $sourceType, $type, $currency);

        if ($average === null) {
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

    private function getAveragePrice(int $id, string $sourceType, string $type, string $currency): ?float
    {
        $column = $sourceType === 'asset' ? 'asset_id' : 'item_id';

        $rows = Listing::query()
            ->where($column, $id)
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
