<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Location;
use App\Models\Mob;
use App\Models\MobDropAsset;
use Illuminate\Console\Command;

class CheckDrop extends Command
{
    protected $signature = 'check:drop';

    protected $description = '
    Проверка соответствия дропа по выдаче.
        1. Что из моба выпадает /getmob N
        2. По ресурсу, из каких мобов он выпадает /getasset N
    В последнем случае выдаёт не всех монстров. Соответственно считаем 1 вариант правильным.
    ';

    public function handle(): int
    {
        $assets = Asset::whereNotNull('drop_monster')->get();
        // Цикл по ресурсам, у которых есть дроп с мобов
        foreach ($assets as $asset) {

            // Сколько падает данного ресурса у всех мобов
            $count = MobDropAsset::where('asset_id', $asset->id)->count();

            // берём у каких монстров данные ресурсы выпадают
            $drop = $asset->drop_monster;
            // парсим
            $dropAr = explode("\n", $drop);
            $checkAr = [];
            if (!$dropAr) {
                dd('dropAr', $drop);
            }
            // цикл по каждому монстру
            foreach ($dropAr as $mobInfo) {

                if (!preg_match('/^(.+?)🔸(\d+)\s+(.*?)$/u', $mobInfo, $matches)) {
                    dd($matches);
                }
                /*
                 array:4 [
                     0 => "🦤 Падальщик🔸1 ☘️ Фермерские угодья"
                     1 => "🦤 Падальщик"
                     2 => "1"
                     3 => "☘️ Фермерские угодья"
                 ]
                 */

                // находим локацию
                $location = Location::where('title', $matches[3])->first();
                if (!$location) {
                    dd('locationTitle', $matches[3]);
                }

                // находим моба
                $mob = Mob::where('location_id', $location->id)->where('title', $matches[1])->where('level', $matches[2])->first();
                if (!$mob) {
                    dd('mobTitle', $matches[1]);
                }

                // берём с базы соответствие моба и текущего ресурса
                if (!in_array($mob->id, [165, 167])) {
                    $dropAsset = MobDropAsset::where('mob_id', $mob->id)->where('asset_id', $asset->id)->first();
                } else {
                    // 🧜🏻‍♀️ Русалка - одинаковое имя, уровень и город, берем любой дроп из этих двух
                    $dropAsset = MobDropAsset::whereIn('mob_id', [165, 167])->where('asset_id', $asset->id)->first();
                }

                if (!$dropAsset) {
                    dd('dropAsset', $matches, $asset->title, $mob->id, $asset->id);
                }
                $checkAr[] = $dropAsset->mob_id;
            }
            if (count($checkAr) != $count) {
                $chAr = MobDropAsset::where('asset_id', $asset->id)->pluck('mob_id')->toArray();
                $intersectAr = array_intersect($chAr, $checkAr);
                if ($intersectAr != $chAr) {
                    $passFlag = true;
                    $txt = 'Различия для ресурса ID = ' . $asset->id . ' ' . $asset->title . PHP_EOL . PHP_EOL;
                    $diffAr = array_diff($checkAr, $intersectAr);
                    if ($diffAr) {
                        $txt .= 'Различие: в drop_monster ресурс есть, в текущей базе - нет' . PHP_EOL;
                        $mobsDiffAr = Mob::whereIn('id', $diffAr)->pluck('title', 'id')->toArray();
                        foreach ($mobsDiffAr as $mobDiffId => $mobDiffTitle) {
                            $txt .= $mobDiffId . ': ' . $mobDiffTitle . PHP_EOL;
                        }
                        $passFlag = false;
                    }

                    $diffAr = array_diff($chAr, $intersectAr);
                    $diffAr = array_diff($diffAr, [308, 309]);
                    if ($diffAr) {
                        $txt .= 'Различие: в базе значение есть, в drop_monster нет' . PHP_EOL;
                        $mobsDiffAr = Mob::whereIn('id', $diffAr)->pluck('title', 'id')->toArray();
                        foreach ($mobsDiffAr as $mobDiffId => $mobDiffTitle) {
                            $txt .= $mobDiffId . ': ' . $mobDiffTitle . PHP_EOL;
                        }
                        $passFlag = false;
                    }

                    if (!$passFlag) {
                        // dd($txt);
                        \Log::info($txt);
                    }
                }
            }
        }

        return self::SUCCESS;
    }
}
