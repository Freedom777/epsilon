<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\Product;
use App\Models\TgMessage;
use App\Models\TgUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketControllerTest extends TestCase
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
        $this->assertArrayHasKey('product_id', $item);
        $this->assertArrayHasKey('product_name', $item);
        $this->assertArrayHasKey('full_name', $item);
        $this->assertArrayHasKey('buy', $item);
        $this->assertArrayHasKey('sell', $item);
    }

    public function test_returns_buy_listing_details(): void
    {
        $this->seedTestData();

        $response = $this->getJson('/api/market');
        $data = $response->json('data');

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
        [$product, $user, $message] = $this->createBasicEntities();

        $this->makeListing($message, $user, $product, 'buy', 1000);
        $this->makeListing($message, $user, $product, 'buy', 1500);

        $response = $this->getJson('/api/market?currency=gold');
        $data     = $response->json('data');

        $item = collect($data)->firstWhere('product_id', $product->id);
        $this->assertNotNull($item);
        $this->assertEquals(1500, $item['buy']['price']);
    }

    public function test_returns_min_sell_price(): void
    {
        [$product, $user, $message] = $this->createBasicEntities();

        $this->makeListing($message, $user, $product, 'sell', 2000);
        $this->makeListing($message, $user, $product, 'sell', 1200);

        $response = $this->getJson('/api/market?currency=gold');
        $data     = $response->json('data');

        $item = collect($data)->firstWhere('product_id', $product->id);
        $this->assertNotNull($item);
        $this->assertEquals(1200, $item['sell']['price']);
    }

    // =========================================================================
    // Фильтрация по валюте
    // =========================================================================

    public function test_filters_by_gold_currency(): void
    {
        $this->seedTestData();

        $response = $this->getJson('/api/market?currency=gold');
        $response->assertStatus(200);

        foreach ($response->json('data') as $item) {
            if ($item['buy'])  $this->assertEquals('gold', $item['buy']['currency']);
            if ($item['sell']) $this->assertEquals('gold', $item['sell']['currency']);
        }
    }

    public function test_filters_by_cookie_currency(): void
    {
        $this->seedTestData();

        $response = $this->getJson('/api/market?currency=cookie');
        $response->assertStatus(200);

        foreach ($response->json('data') as $item) {
            if ($item['buy'])  $this->assertEquals('cookie', $item['buy']['currency']);
            if ($item['sell']) $this->assertEquals('cookie', $item['sell']['currency']);
        }
    }

    // =========================================================================
    // Фильтрация по product_id
    // =========================================================================

    public function test_filters_by_product_id(): void
    {
        [$product1, $user, $message] = $this->createBasicEntities('Товар А');
        [$product2]                  = $this->createBasicEntities('Товар Б');

        $this->makeListing($message, $user, $product1, 'sell', 500);
        $this->makeListing($message, $user, $product2, 'sell', 1000);

        $response = $this->getJson("/api/market?product_id={$product1->id}");
        $data     = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals($product1->id, $data[0]['product_id']);
    }

    public function test_filters_by_multiple_product_ids(): void
    {
        [$product1, $user, $message] = $this->createBasicEntities('Товар 1');
        [$product2]                  = $this->createBasicEntities('Товар 2');
        [$product3]                  = $this->createBasicEntities('Товар 3');

        foreach ([$product1, $product2, $product3] as $p) {
            $this->makeListing($message, $user, $p, 'sell', 500);
        }

        $ids      = "{$product1->id},{$product2->id}";
        $response = $this->getJson("/api/market?product_id={$ids}");

        $this->assertCount(2, $response->json('data'));
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

    // =========================================================================
    // Аномалии
    // =========================================================================

    public function test_excludes_invalid_listings(): void
    {
        [$product, $user, $message] = $this->createBasicEntities();

        $this->makeListing($message, $user, $product, 'sell', 999999, 'invalid');

        $data = $this->getJson('/api/market')->json('data');
        $item = collect($data)->firstWhere('product_id', $product->id);

        $this->assertNull($item);
    }

    public function test_listing_with_null_product_id_not_shown(): void
    {
        [, $user, $message] = $this->createBasicEntities();

        // Листинг без привязки к товару (товар на модерации)
        Listing::create([
            'tg_message_id' => $message->id,
            'tg_user_id'    => $user->id,
            'product_id'    => null,
            'type'          => 'sell',
            'price'         => 500,
            'currency'      => 'gold',
            'posted_at'     => now(),
            'status'        => 'needs_review',
        ]);

        $data = $this->getJson('/api/market')->json('data');
        // needs_review листинги без product_id не должны появляться в выдаче
        $this->assertEmpty(collect($data)->where('product_id', null));
    }

    // =========================================================================
    // Новые поля
    // =========================================================================

    public function test_listing_stores_enhancement_and_durability(): void
    {
        [$product, $user, $message] = $this->createBasicEntities();

        $listing = Listing::create([
            'tg_message_id'      => $message->id,
            'tg_user_id'         => $user->id,
            'product_id'         => $product->id,
            'type'               => 'sell',
            'price'              => 8000,
            'currency'           => 'gold',
            'quantity'           => null,
            'enhancement'        => 3,
            'durability_current' => 47,
            'durability_max'     => 47,
            'posted_at'          => now(),
            'status'             => 'ok',
        ]);

        $this->assertEquals(3, $listing->fresh()->enhancement);
        $this->assertEquals(47, $listing->fresh()->durability_current);
        $this->assertEquals(47, $listing->fresh()->durability_max);
    }

    // =========================================================================
    // Хелперы
    // =========================================================================

    private function createBasicEntities(string $productName = 'Тестовый товар'): array
    {
        $product = Product::create([
            'name'            => $productName,
            'normalized_name' => mb_strtolower($productName),
            'status'          => 'ok',
            'is_verified'     => false,
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
            'raw_text'      => '#продам ' . $productName . ' - 1000💰',
            'tg_link'       => 'https://t.me/testchat/12345',
            'sent_at'       => now(),
            'is_parsed'     => true,
        ]);

        return [$product, $user, $message];
    }

    private function makeListing(
        TgMessage $message,
        TgUser $user,
        Product $product,
        string $type,
        int $price,
        string $status = 'ok',
        string $currency = 'gold'
    ): Listing {
        return Listing::create([
            'tg_message_id' => $message->id,
            'tg_user_id'    => $user->id,
            'product_id'    => $product->id,
            'type'          => $type,
            'price'         => $price,
            'currency'      => $currency,
            'posted_at'     => now()->subDays(1),
            'status'        => $status,
        ]);
    }

    private function seedTestData(): void
    {
        [$product, $user, $message] = $this->createBasicEntities();

        $this->makeListing($message, $user, $product, 'buy', 900);
        $this->makeListing($message, $user, $product, 'sell', 1100);

        [$product2] = $this->createBasicEntities('Внешний вид: Орк-призрак');
        $this->makeListing($message, $user, $product2, 'sell', 80, 'ok', 'cookie');
    }
}
