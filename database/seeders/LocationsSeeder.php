<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationsSeeder extends BaseSeeder
{
    private string $usersFile = 'database/seeders/data/locations.csv';

    public function run(): void
    {
        $this->command->info('Импорт локаций...');

        $rows = $this->readCsv($this->usersFile);
        $imported = 0;

        foreach ($rows as $row) {
            $id                 = (int) trim($row['id']);
            $city_id            = (int) trim($row['city_id']) ?: null;
            $title              = trim($row['title']) ?: null;
            $normalized_title   = trim($row['normalized_title']) ?: null;

            if (!$id) {
                continue;
            }

            Location::create([
                'id' => $id,
                'city_id' => $city_id,
                'title' => $title,
                'normalized_title' => $normalized_title,
            ]);
            $imported++;
        }

        $this->command->info("  Добавлено: {$imported}");
    }
}
