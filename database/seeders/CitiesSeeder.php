<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;

class CitiesSeeder extends Seeder
{
    private string $usersFile = 'database/seeders/data/cities.csv';

    public function run(): void
    {
        $this->command->info('Импорт городов...');

        $rows = $this->readCsv($this->usersFile);
        $imported = 0;

        foreach ($rows as $row) {
            $id                 = (int) trim($row['id']);
            $title              = trim($row['title']) ?: null;
            $normalized_title   = trim($row['normalized_title']) ?: null;

            if (!$id) {
                continue;
            }

            City::create([
                'id' => $id,
                'title' => $title,
                'normalized_title' => $normalized_title,
            ]);
            $imported++;
        }

        $this->command->info("  Добавлено: {$imported}");
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
