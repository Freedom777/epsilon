<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;

class CitiesSeeder extends BaseSeeder
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
}
