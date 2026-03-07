<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Mob;
use App\Models\MobDropAsset;
use Illuminate\Console\Command;

class FillMobDropAssets extends Command
{
    protected $signature = 'mob:fill-drop-assets';

    protected $description = 'Наполнение таблицы mob_drop_assets';

    public function handle(): int
    {
        $assets = Asset::whereNotNull('title')->pluck('title', 'id')->toArray();
        $mobDropAssets = Mob::whereNotNull('drop_asset')->pluck('drop_asset', 'id');

        foreach ($mobDropAssets as $mobId => $dropAssets) {
            foreach ($dropAssets as $dropAsset) {
                $assetId = array_search($dropAsset, $assets);
                if (false !== $assetId) {
                    MobDropAsset::create([
                        'mob_id' => $mobId,
                        'asset_id' => $assetId,
                    ]);
                }
            }

        }

        return self::SUCCESS;
    }
}
