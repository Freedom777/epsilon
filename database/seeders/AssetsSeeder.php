<?php

namespace Database\Seeders;

use App\Enums\MainStatusEnum;
use App\Models\Asset;

class AssetsSeeder extends BaseSeeder
{
    private string $assetsFile = 'database/seeders/data/assets.csv';

    public function run(): void
    {
        $this->command->info('Импорт ресурсов ...');

        $rows = $this->readCsv($this->assetsFile);
        $imported = 0;
        foreach ($rows as $row) {
            $id                 = (int) trim($row['id']);
            $title              = trim($row['title']);
            $normalizedTitle    = trim($row['normalized_title']) ?: null;
            $type               = trim($row['type']) ?: null;
            $subtype            = trim($row['subtype']) ?: null;
            $grade              = trim($row['grade']) ?: null;
            $isPersonal         = (bool) trim($row['is_personal']);
            $isEvent            = (bool) trim($row['is_event']);
            $status             = MainStatusEnum::OK;
            $description        = trim($row['description']) ?: null;
            $rawResponse        = trim($row['raw_response']);

            if ($id) {
                Asset::create([
                    'id'                => $id,
                    'title'             => $title,
                    'normalized_title'  => $normalizedTitle,
                    'type'              => $type,
                    'subtype'           => $subtype,
                    'grade'             => $grade,
                    'is_personal'       => $isPersonal,
                    'is_event'          => $isEvent,
                    'status'            => $status,
                    'description'       => $description,
                    'raw_response'      => $rawResponse,
                ]);
                $imported++;
            }
        }

        $this->command->info("  Добавлено: {$imported}");
    }
}
