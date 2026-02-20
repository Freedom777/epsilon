<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Listing;
use App\Models\TgMessage;
use App\Models\TgUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketAssetTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // JSON формат
    // =========================================================================

    public function test_returns_json_by_default(): void
    {
        $response = $this->getJson('/api/market');
        $response->assertStatus(200)
                 ->assertJsonStructure(['meta', 'data']);
    }

    public function test_returns_meta_information(): void
    {
        $response = $this->getJson('/api/market');
        $response->assertStatus(200)
                 ->assertJsonPath('meta.days', 30)
                 ->assertJsonPath('meta.currency', 'all');
    }

    public function test_returns_market_data_with_correct_structure(): void
    {
        $this->seedTestData();

        $response = $this->getJson('/api/market');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $item = $data[0];
        $this->assertArrayHasKey('asset_id', $item);
        $this->assertArrayHasKey('item_id', $item);
        $this->assertArrayHasKey('product_name', $item);
        $this->assertArrayHasKey('buy', $item);
        $this->assertArrayHasKey('sell', $item);
    }

    public function test_asset_item_id_is_null(): void
    {
        $this->seedTestData();

        $data = $this->getJson('/api/market')->json('data');

        foreach ($data as $row) {
            if ($row['asset_id'] !== null) {
                $this->assertNull($row['item_id']);
            }
        }
    }

    public function test_returns_buy_listing_details(): void
    {
        $this->seedTestData();

        $data = $this->getJson('/api/market')->json('data');
        $item = collect($data)->first(fn($d) => $d['buy'] !== null);
        $this->assertNotNull($item);

        $buy = $item['buy'];
        $this->assertArrayHasKey('price', $buy);
        $this->assertArrayHasKey('currency', $buy);
        $this->assertArrayHasKey('posted_at', $buy);
        $this->assertArrayHasKey('tg_link', $buy);
        $this->assertArrayHasKey('user_display', $buy);
        $this->assertArrayHasKey('user_tg_link', $buy);
    }

    // =========================================================================
    // Цены
    // =========================================================================

    public function test_returns_max_buy_price(): void
    {
        [$asset, $user, $message] = $this->createBasicEntities();

        $this->makeListing($message, $user, $asset, 'buy', 1000);
        $this->makeListing($message, $user, $asset, 'buy', 1500);

        $data = $this->getJson('/api/market?currency=gold')->json('data');
        $item = collect($data)->firstWhere('asset_id', $asset->id);

        $this->assertNotNull($item);
        $this->assertEquals(1500, $item['buy']['price']);
    }

    public function test_returns_min_sell_price(): void
    {
        [$asset, $user, $message] = $this->createBasicEntities();

        $this->makeListing($message, $user, $asset, 'sell', 2000);
        $this->makeListing($message, $user, $asset, 'sell', 1200);

        $data = $this->getJson('/api/market?currency=gold')->json('data');
        $item = collect($data)->firstWhere('asset_id', $asset->id);

        $this->assertNotNull($item);
        $this->assertEquals(1200, $item['sell']['price']);
    }

    // =========================================================================
    // Фильтрация по валюте
    // =========================================================================

    public function test_filters_by_gold_currency(): void
    {
        $this->seedTestData();

        $data = $this->getJson('/api/market?currency=gold')->json('data');

        foreach ($data as $item) {
            if ($item['buy'])  $this->assertEquals('gold', $item['buy']['currency']);
            if ($item['sell']) $this->assertEquals('gold', $item['sell']['currency']);
        }
    }

    public function test_filters_by_cookie_currency(): void
    {
        $this->seedTestData();

        $data = $this->getJson('/api/market?currency=cookie')->json('data');

        foreach ($data as $item) {
            if ($item['buy'])  $this->assertEquals('cookie', $item['buy']['currency']);
            if ($item['sell']) $this->assertEquals('cookie', $item['sell']['currency']);
        }
    }

    // =========================================================================
    // Фильтрация по asset_id
    // =========================================================================

    public function test_filters_by_asset_id(): void
    {
        [$asset1, $user, $message] = $this->createBasicEntities('Товар А');
        [$asset2]                  = $this->createBasicEntities('Товар Б');

        $this->makeListing($message, $user, $asset1, 'sell', 500);
        $this->makeListing($message, $user, $asset2, 'sell', 1000);

        $data = $this->getJson("/api/market?asset_id={$asset1->id}")->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals($asset1->id, $data[0]['asset_id']);
    }

    public function test_filters_by_multiple_asset_ids(): void
    {
        [$asset1, $user, $message] = $this->createBasicEntities('Товар 1');
        [$asset2]                  = $this->createBasicEntities('Товар 2');
        [$asset3]                  = $this->createBasicEntities('Товар 3');

        foreach ([$asset1, $asset2, $asset3] as $a) {
            $this->makeListing($message, $user, $a, 'sell', 500);
        }

        $ids  = "{$asset1->id},{$asset2->id}";
        $data = $this->getJson("/api/market?asset_id={$ids}")->json('data');

        $this->assertCount(2, $data);
    }

    // =========================================================================
    // HTML формат
    // =========================================================================

    public function test_returns_html_when_format_html(): void
    {
        $response = $this->get('/api/market?format=html');
        $response->assertStatus(200);
        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<table', $response->getContent());
        $this->assertStringContainsString('Epsilion War', $response->getContent());
    }

    public function test_html_contains_asset_title(): void
    {
        [$asset, $user, $message] = $this->createBasicEntities('Зелье здоровья');
        $this->makeListing($message, $user, $asset, 'sell', 500);

        $content = $this->get('/api/market?format=html')->getContent();
        $this->assertStringContainsString('Зелье здоровья', $content);
    }

    // =========================================================================
    // Аномалии и статусы
    // =========================================================================

    public function test_excludes_invalid_listings(): void
    {
        [$asset, $user, $message] = $this->createBasicEntities();

        $this->makeListing($message, $user, $asset, 'sell', 999999, 'invalid');

        $data = $this->getJson('/api/market')->json('data');
        $item = collect($data)->firstWhere('asset_id', $asset->id);

        $this->assertNull($item);
    }

    public function test_listing_without_asset_or_item_not_shown(): void
    {
        [, $user, $message] = $this->createBasicEntities();

        Listing::create([
            'tg_message_id' => $message->id,
            'tg_user_id'    => $user->id,
            'asset_id'      => null,
            'item_id'       => null,
            'type'          => 'sell',
            'price'         => 500,
            'currency'      => 'gold',
            'posted_at'     => now(),
            'status'        => 'needs_review',
        ]);

        $data            = $this->getJson('/api/market')->json('data');
        $withoutProduct  = collect($data)->filter(
            fn($item) => $item['asset_id'] === null && $item['item_id'] === null
        );

        $this->assertEmpty($withoutProduct);
    }

    // =========================================================================
    // Хелперы
    // =========================================================================

    private function createBasicEntities(string $title = 'Тестовый расходник'): array
    {
        $asset = Asset::create([
            'title'            => $title,
            'normalized_title' => mb_strtolower($title),
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
            'raw_text'      => '#продам ' . $title . ' - 1000💰',
            'tg_link'       => 'https://t.me/testchat/12345',
            'sent_at'       => now(),
            'is_parsed'     => true,
        ]);

        return [$asset, $user, $message];
    }

    private function makeListing(
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

    private function seedTestData(): void
    {
        [$asset, $user, $message] = $this->createBasicEntities();
        $this->makeListing($message, $user, $asset, 'buy', 900);
        $this->makeListing($message, $user, $asset, 'sell', 1100);

        [$asset2] = $this->createBasicEntities('Внешний вид: Орк-призрак');
        $this->makeListing($message, $user, $asset2, 'sell', 80, 'ok', 'cookie');
    }
}
