<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Загружает начальные цены из CSV для базовой линии детектора аномалий.
 *
 * Создаёт синтетические listings на основе медианных цен из CSV.
 * Это нужно чтобы с первого дня работы парсера детектор аномалий
 * имел достаточно данных для сравнения (минимум 3 записи на товар).
 *
 * Записи помечаются как seeded (через tg_message_id = null и
 * специальный статус) чтобы их можно было отличить от реальных.
 */
class InitialPricesSeeder extends Seeder
{
    private string $pricesFile = 'database/seeders/data/prices_clean.csv';

    public function run(): void
    {
        $this->command->info('Импорт начальных цен (базовая линия для аномалий)...');

        $rows = $this->readCsv($this->pricesFile);
        $imported = 0;
        $skipped  = 0;

        // Используем фиксированную дату — начало периода данных
        $seededAt = now()->subDays(30);

        foreach ($rows as $row) {
            $norm     = trim($row['normalized_name']);
            $type     = trim($row['type']);
            $currency = trim($row['currency']);
            $median   = (int) $row['median_price'];
            $min      = (int) $row['min_price'];
            $max      = (int) $row['max_price'];
            $count    = (int) $row['sample_count'];

            if (!$norm || !$median || !in_array($type, ['buy', 'sell']) || !in_array($currency, ['gold', 'cookie'])) {
                $skipped++;
                continue;
            }

            // Находим товар — ищем по normalized_name без учёта грейда
            // (в prices.csv грейд уже встроен в normalized_name)
            $product = DB::table('products')
                ->where('normalized_name', $norm)
                ->whereNull('parent_id')  // только основные записи, не алиасы
                ->first();

            if (!$product) {
                $skipped++;
                continue;
            }

            // Проверяем — нет ли уже seeded-записей для этого товара
            $alreadySeeded = DB::table('listings')
                ->where('product_id', $product->id)
                ->where('type', $type)
                ->where('currency', $currency)
                ->whereNull('tg_message_id')
                ->exists();

            if ($alreadySeeded) {
                $skipped++;
                continue;
            }

            // Создаём 3 записи: min, median, max — достаточно для anomaly detector
            // (min_samples = 3 в конфиге)
            $prices = array_unique([$min, $median, $max]);

            // Если все три одинаковые — дублируем медиану
            while (count($prices) < 3) {
                $prices[] = $median;
            }
            $prices = array_values(array_slice($prices, 0, 3));

            foreach ($prices as $i => $price) {
                DB::table('listings')->insert([
                    'tg_message_id'  => null,   // null = seeded запись, не из реального сообщения
                    'tg_user_id'     => null,
                    'product_id'     => $product->id,
                    'type'           => $type,
                    'price'          => $price,
                    'currency'       => $currency,
                    'quantity'       => null,
                    'posted_at'      => $seededAt->copy()->subHours($i * 24),
                    'status'         => 'ok',
                    'anomaly_reason' => null,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }

            $imported++;
        }

        $this->command->info("  Обработано товаров: {$imported}, пропущено: {$skipped}");
        $this->command->info("  Создано listings: " . ($imported * 3));
    }

    private function readCsv(string $relativePath): array
    {
        $path = base_path($relativePath);

        if (!file_exists($path)) {
            $this->command->error("Файл не найден: {$path}");
            return [];
        }

        $rows = [];
        $handle = fopen($path, 'r');
        $headers = null;

        while (($line = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = array_map('trim', $line);
                continue;
            }
            if (count($line) === count($headers)) {
                $rows[] = array_combine($headers, $line);
            }
        }

        fclose($handle);
        return $rows;
    }
}
