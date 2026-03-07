<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\Mob;
use App\Models\MobItemDrop;
use App\Services\MatchingService;
use Illuminate\Console\Command;

class FillMobItemDrops extends Command
{
    protected $signature = 'mob:fill-item-drops';

    protected $description = 'Наполнение таблицы mob_item_drops';

    public function handle(MatchingService $matchingService): int
    {
        $items = Item::whereNotNull('title')->pluck('title', 'id')->toArray();
        $mobDropItems = Mob::whereNotNull('drop_item')->pluck('drop_item', 'id');

        foreach ($mobDropItems as $mobId => $dropItems) {
            foreach ($dropItems as $dropItem) {
                $itemId = array_search($dropItem, $items);
                if (false !== $itemId) {
                    MobItemDrop::create([
                        'mob_id' => $mobId,
                        'item_id' => $itemId,
                    ]);
                }
            }
        }

        return self::SUCCESS;
    }
}
