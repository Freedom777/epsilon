<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Mob;
use App\Models\MobAssetDrop;
use App\Services\MatchingService;
use Illuminate\Console\Command;

class FillMobAssetDrops extends Command
{
    protected $signature = 'mob:fill-asset-drops';

    protected $description = 'Наполнение таблицы mob_asset_drops';

    public function __construct(
        private readonly MatchingService      $matchingService
    ) {
        parent::__construct();
    }

    public function handle(MatchingService $matchingService): int
    {
        $assets = Asset::whereNotNull('title')->pluck('title', 'id')->toArray();
        $mobDropAssets = Mob::whereNotNull('drop_asset')->pluck('drop_asset', 'id');


        foreach ($mobDropAssets as $mobId => $dropAssets) {
            foreach ($dropAssets as $dropAsset) {
                $assetId = array_search($dropAsset, $assets);
                if (false !== $assetId) {
                    MobAssetDrop::create([
                        'mob_id' => $mobId,
                        'asset_id' => $assetId,
                    ]);
                }
            }

        }

        return self::SUCCESS;
    }
}
