<?php

namespace Database\Seeders;

use App\Models\MobDropAsset;
use App\Models\MobDropItem;

class LinkTablesSeeder extends BaseSeeder
{
    private string $dropAssetsFile = 'database/seeders/data/mob_drop_assets.csv';
    private string $dropItemsFile = 'database/seeders/data/mob_drop_items.csv';

    public function run(): void
    {
        $this->command->info('Импорт связей, дроп ресурсов из мобов ...');

        $rows = $this->readCsv($this->dropAssetsFile);
        $imported = 0;

        foreach ($rows as $row) {
            $mobId      = (int) trim($row['mob_id']);
            $assetId    = (int) trim($row['asset_id']);

            if (!$mobId || !$assetId) {
                continue;
            }

            MobDropAsset::create([
                'mob_id'    => $mobId,
                'asset_id'  => $assetId,
            ]);
            $imported++;
        }

        $this->command->info("  Добавлено: {$imported}");

        $this->command->info('Импорт связей, дроп предметов из мобов ...');

        $rows = $this->readCsv($this->dropItemsFile);
        $imported = 0;

        foreach ($rows as $row) {
            $mobId      = (int) trim($row['mob_id']);
            $itemId     = (int) trim($row['item_id']);

            if (!$mobId || !$itemId) {
                continue;
            }

            MobDropItem::create([
                'mob_id'    => $mobId,
                'item_id'   => $itemId,
            ]);
            $imported++;
        }

        $this->command->info("  Добавлено: {$imported}");
    }
}
