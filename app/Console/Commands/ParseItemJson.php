<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Services\MatchingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ParseItemJson extends Command
{
    protected $signature = 'item:parse';

    protected $description = 'Парсинг json предметов';

    public function __construct(
        private readonly MatchingService      $matchingService
    ) {
        parent::__construct();
    }

    public function handle(MatchingService $matchingService): int
    {
        $items = Item::whereNotNull('title')->pluck('title', 'id');
        // $items = array_map('trim', array_map([$this, 'cleanEmoji'], array_filter($items->toArray())));
        $items = array_map('trim', array_map([$this->matchingService, 'normalize'], $items->toArray()));
        /*foreach ($items as $id => $title) {
            echo $id . ': ' . $title . PHP_EOL;
            if ($id > 20) {
                die();
            }
        }*/

        $jsonItems = json_decode(file_get_contents( app_path() . '/../data/' . 'receipts.json'), true);
        $result = [];
        foreach ($jsonItems as $jsonItem) {
            $itemName = $this->matchingService->normalize($jsonItem['item_name']);
            /*if (!empty($jsonItem['grade'])) {
                $itemName .= ' [' . $jsonItem['grade'] . ']';
            }*/
            $id = array_search($itemName, $items);
            if (false !== $id) {
                $itemRec = [
                    'item_id' => $id,
                    'city' => $jsonItem['city'],
                    'crafter' => $jsonItem['crafter'],
                ];
                $result[] = [

                ];
                echo $itemName . PHP_EOL;
            }
        }

        return self::SUCCESS;
    }

    private function cleanEmoji(string $text): string
    {
        return preg_replace('/\p{Emoji}/u', '', $text);
    }
}
