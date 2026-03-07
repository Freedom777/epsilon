<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\Mob;
use App\Models\MobDropItem;
use Illuminate\Console\Command;

class FillMobDropItems extends Command
{
    protected $signature = 'mob:fill-drop-items';

    protected $description = 'Наполнение таблицы mob_drop_items';

    public function handle(): int
    {
        $items = Item::whereNotNull('title')->pluck('title', 'id')->toArray();
        $mobDropItems = Mob::whereNotNull('drop_item')->pluck('drop_item', 'id');

        foreach ($mobDropItems as $mobId => $dropItems) {
            foreach ($dropItems as $dropItem) {
                $itemId = array_search($dropItem, $items);
                if (false !== $itemId) {
                    MobDropItem::create([
                        'mob_id' => $mobId,
                        'item_id' => $itemId,
                    ]);
                }
            }
        }

        return self::SUCCESS;
    }
}
