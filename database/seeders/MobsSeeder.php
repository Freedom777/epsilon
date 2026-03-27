<?php

namespace Database\Seeders;

use App\Enums\MainStatusEnum;
use App\Models\Mob;

class MobsSeeder extends BaseSeeder
{
    private string $mobsFile = 'database/seeders/data/mobs.csv';

    public function run(): void
    {
        $this->command->info('Импорт мобов ...');

        $rows = $this->readCsv($this->mobsFile);
        $imported = 0;

        foreach ($rows as $row) {
            $id             = (int) trim($row['id']);
            $locationId     = (int) trim($row['location_id']);
            $title          = trim($row['title']);
            $level          = (int) trim($row['level']);
            $exp            = (int) trim($row['exp']);
            $gold           = (int) trim($row['gold']);
            $status         = MainStatusEnum::OK;
            $rawResponse    = trim($row['raw_response']);

            if ($id) {
                Mob::create([
                    'id'            => $id,
                    'location_id'   => $locationId,
                    'title'         => $title,
                    'level'         => $level,
                    'exp'           => $exp,
                    'gold'          => $gold,
                    'status'        => $status,
                    'raw_response'  => $rawResponse,
                ]);
                $imported++;
            }
        }

        $this->command->info("  Добавлено: {$imported}");
    }
}
