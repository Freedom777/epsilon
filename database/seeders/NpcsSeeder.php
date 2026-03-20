<?php

namespace Database\Seeders;

use App\Models\Npc;

class NpcsSeeder extends BaseSeeder
{
    private string $npcsFile = 'database/seeders/data/npcs.csv';

    public function run(): void
    {
        $this->command->info('Импорт NPC ...');

        $rows = $this->readCsv($this->npcsFile);
        $imported = 0;

        foreach ($rows as $row) {
            $id                 = (int) trim($row['id']);
            $cityId             = (int) trim($row['city_id']);
            $title              = trim($row['title']);
            $normalizedTitle    = trim($row['normalized_title']);;
            $description        = trim($row['description']);

            if ($id) {
                Npc::create([
                    'id'                => $id,
                    'city_id'           => $cityId,
                    'title'             => $title,
                    'normalized_title'  => $normalizedTitle,
                    'description'       => $description,
                ]);
                $imported++;
            }
        }

        $this->command->info("  Добавлено: {$imported}");
    }
}
