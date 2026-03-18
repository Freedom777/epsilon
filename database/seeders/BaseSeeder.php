<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

abstract class BaseSeeder extends Seeder
{
    // -------------------------------------------------------------------------
    // Хелперы
    // -------------------------------------------------------------------------

    protected function readCsv(string $relativePath): array
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
                $line = array_map(
                    fn($value) => $value === 'NULL' ? null : $value,
                    $line
                );
                $rows[] = array_combine($headers, $line);
            }
        }

        fclose($handle);
        return $rows;
    }

    protected function normalize(string $name): string
    {
        // Убираем эмодзи
        $name = preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27FF}\x{2300}-\x{23FF}\x{FE00}-\x{FEFF}]+/u', '', $name);
        // Убираем лишние пробелы и приводим к нижнему регистру
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    }
}
