<?php

namespace App\Console\Commands;

use App\Models\PriceReference;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CalculatePriceReferences extends Command
{
    protected $signature = 'prices:calculate
                            {--days=30 : За сколько дней считать (число или "all")}
                            {--force : Перезаписать записи с is_manual=true}';

    protected $description = 'Рассчитывает справочные цены из таблицы listings (только gold)';

    public function handle(): int
    {
        $daysRaw = $this->option('days');
        $force   = (bool) $this->option('force');
        $isAll   = strtolower($daysRaw) === 'all';
        $days    = $isAll ? null : (int) $daysRaw;
        $since   = $isAll ? null : now()->subDays($days);

        $periodLabel = $isAll ? 'весь период' : "последние {$days} дней";
        $this->info("Расчёт цен за {$periodLabel}...");

        // Собираем статистику по assets
        $assetStats = $this->getStats('asset_id', $since);
        $this->info("Найдено assets с листингами: {$assetStats->count()}");

        // Собираем статистику по items
        $itemStats = $this->getStats('item_id', $since);
        $this->info("Найдено items с листингами: {$itemStats->count()}");

        $created = 0;
        $updated = 0;
        $skipped = 0;

        // Assets
        foreach ($assetStats as $assetId => $stats) {
            $result = $this->upsertReference(
                itemId: null,
                assetId: $assetId,
                stats: $stats,
                days: $days,
                force: $force,
            );

            match ($result) {
                'created' => $created++,
                'updated' => $updated++,
                'skipped' => $skipped++,
            };
        }

        // Items
        foreach ($itemStats as $itemId => $stats) {
            $result = $this->upsertReference(
                itemId: $itemId,
                assetId: null,
                stats: $stats,
                days: $days,
                force: $force,
            );

            match ($result) {
                'created' => $created++,
                'updated' => $updated++,
                'skipped' => $skipped++,
            };
        }

        $this->info("Готово: создано {$created}, обновлено {$updated}, пропущено (manual) {$skipped}");

        return self::SUCCESS;
    }

    /**
     * Получает min/avg/max по buy и sell для каждого product_id.
     *
     * @return \Illuminate\Support\Collection<int, array{
     *     buy_min: ?int, buy_avg: ?int, buy_max: ?int,
     *     sell_min: ?int, sell_avg: ?int, sell_max: ?int,
     *     sample_count: int
     * }>
     */
    private function getStats(string $column, ?\Carbon\Carbon $since): \Illuminate\Support\Collection
    {
        $query = DB::table('listings')
            ->whereNotNull($column)
            ->whereNotNull('price')
            ->where('currency', 'gold')
            ->where('status', '!=', 'invalid');

        if ($since) {
            $query->where('posted_at', '>=', $since);
        }

        $rows = $query->select([
            $column,
            'type',
            DB::raw('MIN(price) as min_price'),
            DB::raw('ROUND(AVG(price)) as avg_price'),
            DB::raw('MAX(price) as max_price'),
            DB::raw('COUNT(*) as cnt'),
        ])
            ->groupBy($column, 'type')
            ->get();

        // Группируем по product_id, разделяя buy/sell
        $result = collect();

        foreach ($rows as $row) {
            $id = $row->{$column};

            if (!$result->has($id)) {
                $result[$id] = [
                    'buy_min'  => null, 'buy_avg'  => null, 'buy_max'  => null,
                    'sell_min' => null, 'sell_avg' => null, 'sell_max' => null,
                    'sample_count' => 0,
                ];
            }

            $entry = $result[$id];
            $prefix = $row->type; // 'buy' или 'sell'

            $entry["{$prefix}_min"] = (int) $row->min_price;
            $entry["{$prefix}_avg"] = (int) $row->avg_price;
            $entry["{$prefix}_max"] = (int) $row->max_price;
            $entry['sample_count'] += (int) $row->cnt;

            $result[$id] = $entry;
        }

        return $result;
    }

    private function upsertReference(
        ?int  $itemId,
        ?int  $assetId,
        array $stats,
        ?int  $days,
        bool  $force,
    ): string {
        $conditions = $itemId
            ? ['item_id' => $itemId]
            : ['asset_id' => $assetId];

        $periodDays = $days ?? 0; // 0 = весь период

        $existing = PriceReference::where($conditions)->first();

        if ($existing) {
            if ($existing->is_manual && !$force) {
                return 'skipped';
            }

            $existing->update([
                ...$stats,
                'period_days' => $periodDays,
                'is_manual'   => false,
            ]);

            return 'updated';
        }

        PriceReference::create([
            ...$conditions,
            ...$stats,
            'period_days' => $periodDays,
            'is_manual'   => false,
        ]);

        return 'created';
    }
}
