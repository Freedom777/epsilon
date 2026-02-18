<?php

namespace Tests\Unit;

use App\Services\MessageParser;
use Tests\TestCase;

class MessageParserTest extends TestCase
{
    private MessageParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new MessageParser();
    }

    // =========================================================================
    // detectTypes
    // =========================================================================

    public function test_detect_sell_by_tag(): void
    {
        $types = $this->parser->detectTypes('#продам\n🔖 Свиток заточки [III] - 1000💰');
        $this->assertContains('sell', $types);
    }

    public function test_detect_buy_by_tag(): void
    {
        $types = $this->parser->detectTypes('#куплю\n🛑 Философский камень - 100💰');
        $this->assertContains('buy', $types);
    }

    public function test_detect_by_keyword(): void
    {
        $types = $this->parser->detectTypes('куплю философский камень');
        $this->assertContains('buy', $types);
    }

    public function test_detect_multiple_types(): void
    {
        $types = $this->parser->detectTypes("#продам\nТовар А\n#куплю\nТовар Б");
        $this->assertContains('sell', $types);
        $this->assertContains('buy', $types);
    }

    // =========================================================================
    // extractPrice
    // =========================================================================

    public function test_extract_gold_price(): void
    {
        $result = $this->parser->extractPrice('Свиток - 1350💰');
        $this->assertEquals(['price' => 1350, 'currency' => 'gold'], $result);
    }

    public function test_extract_cookie_price(): void
    {
        $result = $this->parser->extractPrice('Свиток Кселеса - 100🍪');
        $this->assertEquals(['price' => 100, 'currency' => 'cookie'], $result);
    }

    public function test_extract_price_with_spaces(): void
    {
        $result = $this->parser->extractPrice('Ремкомплект - 3 300💰');
        $this->assertEquals(3300, $result['price']);
    }

    public function test_extract_price_returns_null_when_absent(): void
    {
        $this->assertNull($this->parser->extractPrice('Просто текст без цены'));
    }

    // =========================================================================
    // parseProductLine — базовые случаи
    // =========================================================================

    public function test_parse_simple_sell_line(): void
    {
        $result = $this->parser->parseProductLine('🔖 Безопасный свиток заточки [III] - 1350💰');

        $this->assertEquals('🔖', $result['icon']);
        $this->assertEquals('Безопасный свиток заточки', $result['name']);
        $this->assertEquals('III', $result['grade']);
        $this->assertNull($result['enhancement']);
        $this->assertNull($result['durability_current']);
        $this->assertEquals(1350, $result['price']);
        $this->assertEquals('gold', $result['currency']);
    }

    public function test_parse_line_with_grade_and_enhancement(): void
    {
        $result = $this->parser->parseProductLine('📿 Amulet Of Sea Water +3 [III+] - 5000💰');

        $this->assertEquals('Amulet Of Sea Water', $result['name']);
        $this->assertEquals('III+', $result['grade']);
        $this->assertEquals(3, $result['enhancement']);
        $this->assertNull($result['durability_current']);
    }

    public function test_parse_line_with_grade_enhancement_and_durability(): void
    {
        $result = $this->parser->parseProductLine('📿 Amulet Of Sea Water +3 [III+] (47/47) - 5000💰');

        $this->assertEquals('Amulet Of Sea Water', $result['name']);
        $this->assertEquals('III+', $result['grade']);
        $this->assertEquals(3, $result['enhancement']);
        $this->assertEquals(47, $result['durability_current']);
        $this->assertEquals(47, $result['durability_max']);
    }

    public function test_parse_line_with_durability_without_parens(): void
    {
        $result = $this->parser->parseProductLine('🛡 Adagra [III] +1 44/49 - 8000💰');

        $this->assertEquals('Adagra', $result['name']);
        $this->assertEquals('III', $result['grade']);
        $this->assertEquals(1, $result['enhancement']);
        $this->assertEquals(44, $result['durability_current']);
        $this->assertEquals(49, $result['durability_max']);
    }

    public function test_parse_line_without_grade(): void
    {
        $result = $this->parser->parseProductLine('🛑 Философский камень - 100💰');

        $this->assertEquals('Философский камень', $result['name']);
        $this->assertNull($result['grade']);
        $this->assertNull($result['enhancement']);
    }

    public function test_parse_line_with_quantity(): void
    {
        $result = $this->parser->parseProductLine('🥩 Кусок мяса - 358шт - 75💰');

        $this->assertEquals('Кусок мяса', $result['name']);
        $this->assertEquals(358, $result['quantity']);
    }

    public function test_parse_line_with_double_dash_quantity(): void
    {
        $result = $this->parser->parseProductLine('✴️ Дуб - - 5шт');

        $this->assertEquals('Дуб', $result['name']);
        $this->assertEquals(5, $result['quantity']);
    }

    public function test_parse_line_cookie_currency(): void
    {
        $result = $this->parser->parseProductLine('🔖 Свиток Кселеса - 100🍪');

        $this->assertEquals('Свиток Кселеса', $result['name']);
        $this->assertEquals(100, $result['price']);
        $this->assertEquals('cookie', $result['currency']);
    }

    // =========================================================================
    // Очистка хвостовых символов
    // =========================================================================

    public function test_cleanup_trailing_plus(): void
    {
        $result = $this->parser->parseProductLine('📿 Amulet of Sea Depths +');
        $this->assertEquals('Amulet of Sea Depths', $result['name']);
    }

    public function test_cleanup_trailing_equals(): void
    {
        $result = $this->parser->parseProductLine('🔖 Безопасный свиток заточки =');
        $this->assertEquals('Безопасный свиток заточки', $result['name']);
    }

    public function test_cleanup_trailing_slash(): void
    {
        $result = $this->parser->parseProductLine('🌂 Аксессуар материи /');
        $this->assertEquals('Аксессуар материи', $result['name']);
    }

    public function test_cleanup_trailing_sht(): void
    {
        $result = $this->parser->parseProductLine('🥩 Кусок мяса /шт - 75💰');
        $this->assertEquals('Кусок мяса', $result['name']);
        $this->assertEquals(75, $result['price']);
    }

    public function test_cleanup_trailing_dash(): void
    {
        $result = $this->parser->parseProductLine('⚛️ Амарант —');
        $this->assertEquals('Амарант', $result['name']);
    }

    // =========================================================================
    // parseProductLines
    // =========================================================================

    public function test_parse_product_lines_skips_empty(): void
    {
        $text = "🔖 Свиток [III] - 1350💰\n\n🛑 Философский камень - 100💰";
        $items = $this->parser->parseProductLines($text);
        $this->assertCount(2, $items);
    }

    public function test_parse_product_lines_skips_tag_lines(): void
    {
        $text = "#продам\n🔖 Свиток [III] - 1350💰";
        $items = $this->parser->parseProductLines($text);
        $this->assertCount(1, $items);
    }

    // =========================================================================
    // parse — полные сообщения
    // =========================================================================

    public function test_parse_full_sell_message(): void
    {
        $text = "#продам\n📿 Amulet Of Sea Water +3 [III+] (47/47) - 5000💰\n🛑 Философский камень - 100💰";
        $result = $this->parser->parse($text);

        $this->assertContains('sell', $result['types']);
        $this->assertCount(2, $result['listings']);

        $amulet = $result['listings'][0];
        $this->assertEquals('Amulet Of Sea Water', $amulet['name']);
        $this->assertEquals('III+', $amulet['grade']);
        $this->assertEquals(3, $amulet['enhancement']);
        $this->assertEquals(47, $amulet['durability_current']);
        $this->assertEquals(5000, $amulet['price']);
        $this->assertEquals('sell', $amulet['type']);

        $stone = $result['listings'][1];
        $this->assertEquals('Философский камень', $stone['name']);
        $this->assertNull($stone['grade']);
    }

    public function test_parse_full_buy_message(): void
    {
        $text = "#куплю\n🛑 Философский камень - 100💰\n🧪 Удача торговца - 300💰";
        $result = $this->parser->parse($text);

        $this->assertContains('buy', $result['types']);
        $this->assertCount(2, $result['listings']);
        $this->assertEquals('buy', $result['listings'][0]['type']);
    }

    public function test_parse_multi_section_message(): void
    {
        $text = "#продам\n🛑 Философский камень - 100💰\n#куплю\n🔧 Ремкомплект - 5000💰";
        $result = $this->parser->parse($text);

        $this->assertContains('sell', $result['types']);
        $this->assertContains('buy', $result['types']);
        $this->assertCount(2, $result['listings']);

        $types = array_column($result['listings'], 'type');
        $this->assertContains('sell', $types);
        $this->assertContains('buy', $types);
    }

    // =========================================================================
    // parseExchangeLines
    // =========================================================================

    public function test_parse_exchange_basic(): void
    {
        $text = "Мой 🔖 Свиток заточки [III] 2шт\nна 🔖 Свиток заточки [IV] 1шт";
        $result = $this->parser->parseExchangeLines($text);

        $this->assertCount(1, $result);
        $this->assertEquals(2, $result[0]['give_qty']);
        $this->assertEquals(1, $result[0]['want_qty']);
    }

    public function test_parse_exchange_with_surcharge(): void
    {
        $text = "Мой 🔪 Акинак [II]\nна 🔪 Акинак [III] с моей доплатой 2000💰";
        $result = $this->parser->parseExchangeLines($text);

        $this->assertCount(1, $result);
        $this->assertEquals(2000, $result[0]['surcharge']);
        $this->assertEquals('me', $result[0]['surcharge_direction']);
    }

    public function test_parse_returns_empty_for_empty_text(): void
    {
        $result = $this->parser->parse('');
        $this->assertEmpty($result['types']);
        $this->assertEmpty($result['listings']);
    }

    // =========================================================================
    // Грейд V
    // =========================================================================

    public function test_parse_grade_v(): void
    {
        $result = $this->parser->parseProductLine('🔥📙 Активная защита [V] - 40000💰');
        $this->assertEquals('Активная защита', $result['name']);
        $this->assertEquals('V', $result['grade']);
    }
}
