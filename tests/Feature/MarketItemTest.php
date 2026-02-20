<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Listing;
use App\Models\TgMessage;
use App\Models\TgUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketItemTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Структура
    // =========================================================================

    public function test_returns_item_id_in_response(): void
    {
        [$item, $user, $message] = $this->createBasicEntities();
        $this->makeListing($message, $user, $item, 'sell', 5000);

        $data = $this->getJson('/api/market')->json('data');
        $row  = collect($data)->firstWhere('item_id', $item->id);

        $this->assertNotNull($row);
        $this->assertNull($row['asset_id']);
        $this->assertEquals($item->id, $row['item_id']);
    }

    public function test_returns_grade_in_response(): void
    {
        [$item, $user, $message] = $this->createBasicEntities(grade: 'III');
        $this->makeListing($message, $user, $item, 'sell', 5000);

        $data = $this->getJson('/api/market')->json('data');
        $row  = collect($data)->firstWhere('item_id', $item->id);

        $this->assertEquals('III', $row['grade']);
    }

    // =========================================================================
    // Цены
    // =========================================================================

    public function test_returns_max_buy_price_for_item(): void
    {
        [$item, $user, $message] = $this->createBasicEntities();

        $this->makeListing($message, $user, $item, 'buy', 3000);
        $this->makeListing($message, $user, $item, 'buy', 4500);

        $data = $this->getJson('/api/market?currency=gold')->json('data');
        $row  = collect($data)->firstWhere('item_id', $item->id);

        $this->assertNotNull($row);
        $this->assertEquals(4500, $row['buy']['price']);
    }

    public function test_returns_min_sell_price_for_item(): void
    {
        [$item, $user, $message] = $this->createBasicEntities();

        $this->makeListing($message, $user, $item, 'sell', 8000);
        $this->makeListing($message, $user, $item, 'sell', 5500);

        $data = $this->getJson('/api/market?currency=gold')->json('data');
        $row  = collect($data)->firstWhere('item_id', $item->id);

        $this->assertNotNull($row);
        $this->assertEquals(5500, $row['sell']['price']);
    }

    // =========================================================================
    // Фильтрация по item_id
    // =========================================================================

    public function test_filters_by_item_id(): void
    {
        [$item1, $user, $message] = $this->createBasicEntities('Меч рыцаря');
        [$item2]                  = $this->createBasicEntities('Топор берсерка');

        $this->makeListing($message, $user, $item1, 'sell', 5000);
        $this->makeListing($message, $user, $item2, 'sell', 8000);

        $data = $this->getJson("/api/market?item_id={$item1->id}")->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals($item1->id, $data[0]['item_id']);
    }

    public function test_filters_by_multiple_item_ids(): void
    {
        [$item1, $user, $message] = $this->createBasicEntities('Предмет 1');
        [$item2]                  = $this->createBasicEntities('Предмет 2');
        [$item3]                  = $this->createBasicEntities('Предмет 3');

        foreach ([$item1, $item2, $item3] as $i) {
            $this->makeListing($message, $user, $i, 'sell', 5000);
        }

        $ids  = "{$item1->id},{$item2->id}";
        $data = $this->getJson("/api/market?item_id={$ids}")->json('data');

        $this->assertCount(2, $data);
    }

    // =========================================================================
    // Enhancement и durability
    // =========================================================================

    public function test_listing_stores_enhancement(): void
    {
        [$item, $user, $message] = $this->createBasicEntities();

        $listing = Listing::create([
            'tg_message_id' => $message->id,
            'tg_user_id'    => $user->id,
            'asset_id'      => null,
            'item_id'       => $item->id,
            'type'          => 'sell',
            'price'         => 15000,
            'currency'      => 'gold',
            'enhancement'   => 5,
            'posted_at'     => now(),
            'status'        => 'ok',
        ]);

        $this->assertEquals(5, $listing->fresh()->enhancement);
    }

    public function test_listing_stores_durability(): void
    {
        [$item, $user, $message] = $this->createBasicEntities();

        $listing = Listing::create([
            'tg_message_id'      => $message->id,
            'tg_user_id'         => $user->id,
            'asset_id'           => null,
            'item_id'            => $item->id,
            'type'               => 'sell',
            'price'              => 8000,
            'currency'           => 'gold',
            'enhancement'        => 3,
            'durability_current' => 47,
            'durability_max'     => 47,
            'posted_at'          => now(),
            'status'             => 'ok',
        ]);

        $this->assertEquals(3,  $listing->fresh()->enhancement);
        $this->assertEquals(47, $listing->fresh()->durability_current);
        $this->assertEquals(47, $listing->fresh()->durability_max);
    }

    public function test_enhancement_boundary_values(): void
    {
        [$item, $user, $message] = $this->createBasicEntities();

        foreach ([1, 5, 10] as $enhancement) {
            $listing = Listing::create([
                'tg_message_id' => $message->id,
                'tg_user_id'    => $user->id,
                'asset_id'      => null,
                'item_id'       => $item->id,
                'type'          => 'sell',
                'price'         => 10000,
                'currency'      => 'gold',
                'enhancement'   => $enhancement,
                'posted_at'     => now(),
                'status'        => 'ok',
            ]);

            $this->assertEquals($enhancement, $listing->fresh()->enhancement);
        }
    }

    // =========================================================================
    // Фильтрация по валюте
    // =========================================================================

    public function test_filters_by_gold_currency(): void
    {
        $this->seedTestData();

        $data = $this->getJson('/api/market?currency=gold')->json('data');

        foreach ($data as $row) {
            if ($row['buy'])  $this->assertEquals('gold', $row['buy']['currency']);
            if ($row['sell']) $this->assertEquals('gold', $row['sell']['currency']);
        }
    }

    public function test_filters_by_cookie_currency(): void
    {
        $this->seedTestData();

        $data = $this->getJson('/api/market?currency=cookie')->json('data');

        foreach ($data as $row) {
            if ($row['buy'])  $this->assertEquals('cookie', $row['buy']['currency']);
            if ($row['sell']) $this->assertEquals('cookie', $row['sell']['currency']);
        }
    }

    // =========================================================================
    // Аномалии и статусы
    // =========================================================================

    public function test_excludes_invalid_listings(): void
    {
        [$item, $user, $message] = $this->createBasicEntities();
        $this->makeListing($message, $user, $item, 'sell', 999999, 'invalid');

        $data = $this->getJson('/api/market')->json('data');
        $row  = collect($data)->firstWhere('item_id', $item->id);

        $this->assertNull($row);
    }

    // =========================================================================
    // Хелперы
    // =========================================================================

    private function createBasicEntities(
        string  $title = 'Тестовый предмет',
        ?string $grade = null,
        string  $type  = 'оружие',
    ): array {
        $item = Item::create([
            'title'            => $title . ($grade ? " [{$grade}]" : ''),
            'normalized_title' => mb_strtolower($title),
            'type'             => $type,
            'grade'            => $grade,
            'status'           => 'ok',
        ]);

        $user = TgUser::create([
            'tg_id'        => rand(100000, 999999),
            'username'     => 'testuser' . rand(1, 999),
            'display_name' => 'Test User',
        ]);

        $message = TgMessage::create([
            'tg_message_id' => rand(1000, 99999),
            'tg_chat_id'    => -1001234567890,
            'tg_user_id'    => $user->id,
            'raw_text'      => '#продам ' . $title . ' - 5000💰',
            'tg_link'       => 'https://t.me/testchat/12345',
            'sent_at'       => now(),
            'is_parsed'     => true,
        ]);

        return [$item, $user, $message];
    }

    private function makeListing(
        TgMessage $message,
        TgUser    $user,
        Item      $item,
        string    $type,
        int       $price,
        string    $status   = 'ok',
        string    $currency = 'gold'
    ): Listing {
        return Listing::create([
            'tg_message_id' => $message->id,
            'tg_user_id'    => $user->id,
            'asset_id'      => null,
            'item_id'       => $item->id,
            'type'          => $type,
            'price'         => $price,
            'currency'      => $currency,
            'posted_at'     => now()->subDays(1),
            'status'        => $status,
        ]);
    }

    private function seedTestData(): void
    {
        [$item, $user, $message] = $this->createBasicEntities('Меч воина', 'II');
        $this->makeListing($message, $user, $item, 'buy', 4000);
        $this->makeListing($message, $user, $item, 'sell', 6000);

        [$item2] = $this->createBasicEntities('Доспех паладина', 'III');
        $this->makeListing($message, $user, $item2, 'sell', 12000, 'ok', 'cookie');
    }
}
