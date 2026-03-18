<?php

namespace Database\Seeders;

use App\Models\Item;

class ItemsSeeder extends BaseSeeder
{
    private string $itemsFile = 'database/seeders/data/items.csv';

    public function run(): void
    {
        $this->command->info('Импорт предметов ...');

        $rows = $this->readCsv($this->itemsFile);
        $imported = 0;

        foreach ($rows as $row) {
            $id                 = (int) trim($row['id']);
            $title              = trim($row['title']);
            $normalizedTitle    = trim($row['normalized_title']) ?: null;
            $type               = trim($row['type']) ?: null;
            $subtype            = trim($row['subtype']) ?: null;
            $grade              = trim($row['grade']) ?: null;
            $rarity             = trim($row['rarity']) ?: null;
            $durabilityMax      = (int) trim($row['durability_max']);
            $price              = (int) trim($row['price']);
            $isPersonal         = (bool) trim($row['is_personal']);
            $isEvent            = (bool) trim($row['is_event']);
            $extra              = trim($row['extra']) ?: null;
            $description        = trim($row['description']) ?: null;
            $rawResponse        = trim($row['raw_response']);

            if ($id) {
                Item::create([
                    'id'                => $id,
                    'title'             => $title,
                    'normalized_title'  => $normalizedTitle,
                    'type'              => $type,
                    'subtype'           => $subtype,
                    'grade'             => $grade,
                    'rarity'            => $rarity,
                    'durability_max'    => $durabilityMax,
                    'price'             => $price,
                    'is_personal'       => $isPersonal,
                    'is_event'          => $isEvent,
                    'extra'             => $extra,
                    'description'       => $description,
                    'raw_response'      => $rawResponse,
                ]);
                $imported++;
            }
        }

        $this->command->info("  Добавлено: {$imported}");
    }
}
