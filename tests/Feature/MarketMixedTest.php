<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Item;
use App\Models\Listing;
use App\Models\TgMessage;
use App\Models\TgUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketMixedTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Оба типа в одном ответе
    // =========================================================================

    public function test_returns_both_assets_and_items(): void
    {
        [$asset, $user, $message] = $this->createAssetEntity('Зелье здоровья');
        [$item]                   = $this->createItemEntity('Меч воина', 'II');

        $this->makeAssetListing($message, $user, $asset, 'sell', 500);
        $this->makeItemListing($message, $user, $item, 'sell', 5000);

        $data = $this->getJson('/api/market')->json('data');

        $assetRow = collect($data)->firstWhere('asset_id', $asset->id);
        $itemRow  = collect($data)->firstWhere('item_id', $item->id);

        $this->assertNotNull($assetRow);
        $this->assertNotNull($itemRow);
    }

    public function test_asset_row_has_null_item_id(): void
    {
        [$asset, $user, $message] = $this->createAssetEntity();
        $this->makeAssetListing($message, $user, $asset, 'sell', 500);

        $data = $this->getJson('/api/market')->json('data');
        $row  = collect($data)->firstWhere('asset_id', $asset->id);

        $this->assertNull($row['item_id']);
    }

    public function test_item_row_has_null_asset_id(): void
    {
        [$item, $user, $message] = $this->createItemEntity();
        $this->makeItemListing($message, $user, $item, 'sell', 5000);

        $data = $this->getJson('/api/market')->json('data');
        $row  = collect($data)->firstWhere('item_id', $item->id);

        $this->assertNull($row['asset_id']);
    }

    public function test_asset_id_filter_does_not_return_items(): void
    {
        [$asset, $user, $message] = $this->createAssetEntity('Зелье маны');
        [$item]                   = $this->createItemEntity('Топор берсерка');

        $this->makeAssetListing($message, $user, $asset, 'sell', 300);
        $this->makeItemListing($message, $user, $item, 'sell', 7000);

        $data = $this->getJson("/api/market?asset_id={$asset->id}")->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals($asset->id, $data[0]['asset_id']);
        $this->assertNull($data[0]['item_id']);
    }

    public function test_item_id_filter_does_not_return_assets(): void
    {
        [$asset, $user, $message] = $this->createAssetEntity('Свиток заточки');
        [$item]                   = $this->createItemEntity('Шлем паладина');

        $this->makeAssetListing($message, $user, $asset, 'sell', 1000);
        $this->makeItemListing($message, $user, $item, 'sell', 9000);

        $data = $this->getJson("/api/market?item_id={$item->id}")->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals($item->id, $data[0]['item_id']);
        $this->assertNull($data[0]['asset_id']);
    }

    public function test_sorted_alphabetically_across_both_types(): void
    {
        [$asset, $user, $message] = $this->createAssetEntity('Яблоко');
        [$item]                   = $this->createItemEntity('Арбалет');

        $this->makeAssetListing($message, $user, $asset, 'sell', 50);
        $this->makeItemListing($message, $user, $item, 'sell', 3000);

        $data  = $this->getJson('/api/market')->json('data');
        $names = collect($data)->pluck('product_name')->values()->toArray();

        $sorted = $names;
        sort($sorted);

        $this->assertEquals($sorted, $names);
    }

    public function test_currency_filter_applies_to_both_types(): void
    {
        [$asset, $user, $message] = $this->createAssetEntity();
        [$item]                   = $this->createItemEntity();

        $this->makeAssetListing($message, $user, $asset, 'sell', 300,  'ok', 'gold');
        $this->makeAssetListing($message, $user, $asset, 'sell', 200,  'ok', 'cookie');
        $this->makeItemListing($message, $user, $item,   'sell', 5000, 'ok', 'gold');
        $this->makeItemListing($message, $user, $item,   'sell', 4000, 'ok', 'cookie');

        $data = $this->getJson('/api/market?currency=gold')->json('data');

        foreach ($data as $row) {
            if ($row['sell']) $this->assertEquals('gold', $row['sell']['currency']);
            if ($row['buy'])  $this->assertEquals('gold', $row['buy']['currency']);
        }
    }

    public function test_total_in_meta_counts_both_types(): void
    {
        [$asset, $user, $message] = $this->createAssetEntity('Зелье');
        [$item]                   = $this->createItemEntity('Меч');

        $this->makeAssetListing($message, $user, $asset, 'sell', 300);
        $this->makeItemListing($message, $user, $item, 'sell', 5000);

        $response = $this->getJson('/api/market');
        $this->assertEquals(2, $response->json('meta.total'));
    }

    // =========================================================================
    // Хелперы — Asset
    // =========================================================================

    private function createAssetEntity(string $title = 'Тестовый расходник'): array
    {
        $asset = Asset::create([
            'title'            => $title,
            'normalized_title' => mb_strtolower($title),
            'status'           => 'ok',
        ]);

        [$user, $message] = $this->createUserAndMessage($title);

        return [$asset, $user, $message];
    }

    private function makeAssetListing(
        TgMessage $message,
        TgUser    $user,
        Asset     $asset,
        string    $type,
        int       $price,
        string    $status   = 'ok',
        string    $currency = 'gold'
    ): Listing {
        return Listing::create([
            'tg_message_id' => $message->id,
            'tg_user_id'    => $user->id,
            'asset_id'      => $asset->id,
            'item_id'       => null,
            'type'          => $type,
            'price'         => $price,
            'currency'      => $currency,
            'posted_at'     => now()->subDays(1),
            'status'        => $status,
        ]);
    }

    // =========================================================================
    // Хелперы — Item
    // =========================================================================

    private function createItemEntity(
        string  $title = 'Тестовая экипировка',
        ?string $grade = null,
    ): array {
        $item = Item::create([
            'title'            => $title,
            'normalized_title' => mb_strtolower($title),
            'grade'            => $grade,
            'type'             => 'оружие',
            'status'           => 'ok',
        ]);

        [$user, $message] = $this->createUserAndMessage($title);

        return [$item, $user, $message];
    }

    private function makeItemListing(
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

    // =========================================================================
    // Общий хелпер
    // =========================================================================

    private function createUserAndMessage(string $title): array
    {
        $user = TgUser::create([
            'tg_id'        => rand(100000, 999999),
            'username'     => 'testuser' . rand(1, 999),
            'display_name' => 'Test User',
        ]);

        $message = TgMessage::create([
            'tg_message_id' => rand(1000, 99999),
            'tg_chat_id'    => -1001234567890,
            'tg_user_id'    => $user->id,
            'raw_text'      => '#продам ' . $title,
            'tg_link'       => 'https://t.me/testchat/12345',
            'sent_at'       => now(),
            'is_parsed'     => true,
        ]);

        return [$user, $message];
    }
}
