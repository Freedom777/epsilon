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
        $types = $this->parser->detectTypes("#продам\n🔖 Свиток [III] - 1000💰");
        $this->assertContains('sell', $types);
    }

    public function test_detect_buy_by_tag(): void
    {
        $types = $this->parser->detectTypes("#куплю\n🛑 Философский камень - 100💰");
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
    // extractPrice — символьные валюты
    // =========================================================================

    public function test_extract_gold_emoji(): void
    {
        [$price, $currency] = $this->parser->extractPrice('Свиток - 1350💰');
        $this->assertEquals(1350, $price);
        $this->assertEquals('gold', $currency);
    }

    public function test_extract_cookie_emoji(): void
    {
        [$price, $currency] = $this->parser->extractPrice('Свиток - 100🍪');
        $this->assertEquals(100, $price);
        $this->assertEquals('cookie', $currency);
    }

    public function test_extract_price_with_spaces(): void
    {
        [$price] = $this->parser->extractPrice('Ремкомплект - 3 300💰');
        $this->assertEquals(3300, $price);
    }

    // =========================================================================
    // extractPrice — текстовые валюты
    // =========================================================================

    public function test_extract_gold_z(): void
    {
        [$price, $currency] = $this->parser->extractPrice('Свиток [III] - 1350з');
        $this->assertEquals(1350, $price);
        $this->assertEquals('gold', $currency);
    }

    public function test_extract_gold_zlato(): void
    {
        [$price, $currency] = $this->parser->extractPrice('Товар - 5000 злато');
        $this->assertEquals(5000, $price);
        $this->assertEquals('gold', $currency);
    }

    public function test_extract_cookie_pech(): void
    {
        [$price, $currency] = $this->parser->extractPrice('Внешний вид: Орк-призрак - 90 печ');
        $this->assertEquals(90, $price);
        $this->assertEquals('cookie', $currency);
    }

    public function test_extract_cookie_pechenki(): void
    {
        [$price, $currency] = $this->parser->extractPrice('Скин - 79 печеньки');
        $this->assertEquals(79, $price);
        $this->assertEquals('cookie', $currency);
    }

    // =========================================================================
    // extractPrice — точка как разделитель тысяч
    // =========================================================================

    public function test_extract_price_dot_separator(): void
    {
        [$price] = $this->parser->extractPrice('Предмет - 19.999💰');
        $this->assertEquals(19999, $price);
    }

    public function test_extract_price_dot_separator_large(): void
    {
        [$price] = $this->parser->extractPrice('Предмет - 333.333💰');
        $this->assertEquals(333333, $price);
    }

    public function test_extract_price_returns_null_when_absent(): void
    {
        [$price] = $this->parser->extractPrice('Просто текст без цены');
        $this->assertNull($price);
    }

    // =========================================================================
    // parseProductLine — грейды
    // =========================================================================

    public function test_parse_grade_iii(): void
    {
        $result = $this->parser->parseProductLine('🔖 Безопасный свиток заточки [III] - 1350💰');
        $this->assertEquals('Безопасный свиток заточки', $result['name']);
        $this->assertEquals('III', $result['grade']);
    }

    public function test_parse_grade_iiiplus(): void
    {
        $result = $this->parser->parseProductLine('🔪 Чекан Маржаны [III+] - 5500💰');
        $this->assertEquals('Чекан Маржаны', $result['name']);
        $this->assertEquals('III+', $result['grade']);
    }

    public function test_parse_grade_iv(): void
    {
        $result = $this->parser->parseProductLine('🔖 Безопасный свиток заточки [IV] - 1500💰');
        $this->assertEquals('IV', $result['grade']);
    }

    public function test_parse_grade_v(): void
    {
        $result = $this->parser->parseProductLine('📙 Активная защита [V] - 17000з');
        $this->assertEquals('Активная защита', $result['name']);
        $this->assertEquals('V', $result['grade']);
    }

    // =========================================================================
    // parseProductLine — латинские l вместо I
    // =========================================================================

    public function test_parse_fake_roman_ll(): void
    {
        $result = $this->parser->parseProductLine('🔖 Свиток заточки [ll] - 30з');
        $this->assertEquals('Свиток заточки', $result['name']);
        $this->assertEquals('II', $result['grade']);
    }

    public function test_parse_fake_roman_lll(): void
    {
        $result = $this->parser->parseProductLine('🔖 Свиток заточки [lll] - 66з');
        $this->assertEquals('III', $result['grade']);
    }

    public function test_parse_fake_roman_lllplus(): void
    {
        $result = $this->parser->parseProductLine('🎽 Ледяная кольчуга провидца [lll+] - 6000💰');
        $this->assertEquals('III+', $result['grade']);
    }

    // =========================================================================
    // parseProductLine — заточка и прочность
    // =========================================================================

    public function test_parse_enhancement(): void
    {
        $result = $this->parser->parseProductLine('🎽 Crusher Armor [III] +7 (10/41) - 24000💰');
        $this->assertEquals('Crusher Armor', $result['name']);
        $this->assertEquals('III', $result['grade']);
        $this->assertEquals(7, $result['enhancement']);
        $this->assertEquals(10, $result['durability_current']);
        $this->assertEquals(41, $result['durability_max']);
    }

    public function test_parse_enhancement_and_durability_full(): void
    {
        $result = $this->parser->parseProductLine('🔪 Баллок и басселард Пурги [III+] +8 (22/60) - 85.000💰');
        $this->assertEquals('Баллок и басселард Пурги', $result['name']);
        $this->assertEquals('III+', $result['grade']);
        $this->assertEquals(8, $result['enhancement']);
        $this->assertEquals(22, $result['durability_current']);
        $this->assertEquals(60, $result['durability_max']);
        $this->assertEquals(85000, $result['price']);
    }

    // =========================================================================
    // parseProductLine — рецепты
    // =========================================================================

    public function test_parse_recipe_with_grade_in_name(): void
    {
        $result = $this->parser->parseProductLine('📄 Рецепт [III]: Ледяные перчатки стража - 250з');
        $this->assertEquals('Рецепт: Ледяные перчатки стража', $result['name']);
        $this->assertEquals('III', $result['grade']);
        $this->assertEquals(250, $result['price']);
        $this->assertEquals('gold', $result['currency']);
    }

    public function test_parse_recipe_without_space(): void
    {
        $result = $this->parser->parseProductLine('📄Рецепт [III]: Ледяная маска изменника - 250з');
        $this->assertEquals('Рецепт: Ледяная маска изменника', $result['name']);
        $this->assertEquals('III', $result['grade']);
    }

    // =========================================================================
    // parseProductLine — текстовые валюты в реальных сообщениях
    // =========================================================================

    public function test_parse_gold_z_currency(): void
    {
        $result = $this->parser->parseProductLine('🔪 Yatağan of Skeleton [II] - 5000з');
        $this->assertEquals('Yatağan of Skeleton', $result['name']);
        $this->assertEquals('II', $result['grade']);
        $this->assertEquals(5000, $result['price']);
        $this->assertEquals('gold', $result['currency']);
    }

    public function test_parse_cookie_pech_currency(): void
    {
        $result = $this->parser->parseProductLine('🎐 Амулет оракула [IV] - 350 печ');
        $this->assertEquals('Амулет оракула', $result['name']);
        $this->assertEquals('IV', $result['grade']);
        $this->assertEquals(350, $result['price']);
        $this->assertEquals('cookie', $result['currency']);
    }

    public function test_parse_appearance_with_cookie(): void
    {
        $result = $this->parser->parseProductLine('🎨 🧟‍♂️ Внешний вид: Некромант тьмы - 90 печ');
        $this->assertStringContainsString('Внешний вид', $result['name']);
        $this->assertEquals(90, $result['price']);
        $this->assertEquals('cookie', $result['currency']);
    }

    // =========================================================================
    // parseProductLine — очистка хвоста
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

    public function test_cleanup_trailing_dash(): void
    {
        $result = $this->parser->parseProductLine('⚛️ Амарант —');
        $this->assertEquals('Амарант', $result['name']);
    }

    // =========================================================================
    // parse — полные сообщения
    // =========================================================================

    public function test_parse_full_sell_message(): void
    {
        $text   = "#продам\n🔪 Чекан Маржаны [III+] - 5500💰\n🎩 Ледяной марион провидца [III+] - 6000💰";
        $result = $this->parser->parse($text);

        $this->assertContains('sell', $result['types']);
        $this->assertCount(2, $result['listings']);
        $this->assertEquals('sell', $result['listings'][0]['type']);
        $this->assertEquals('Чекан Маржаны', $result['listings'][0]['name']);
        $this->assertEquals('III+', $result['listings'][0]['grade']);
        $this->assertEquals(5500, $result['listings'][0]['price']);
    }

    public function test_parse_full_buy_message(): void
    {
        $text   = "#куплю\n🛑 Философский камень - 75💰\n✴️ Медь - 20💰";
        $result = $this->parser->parse($text);

        $this->assertContains('buy', $result['types']);
        $this->assertCount(2, $result['listings']);
        $this->assertEquals('buy', $result['listings'][0]['type']);
    }

    public function test_parse_multi_section_message(): void
    {
        $text = "#продам\n🛑 Философский камень - 75💰\n#куплю\n✴️ Медь - 20💰";
        $result = $this->parser->parse($text);

        $this->assertContains('sell', $result['types']);
        $this->assertContains('buy', $result['types']);
        $this->assertCount(2, $result['listings']);

        $types = array_column($result['listings'], 'type');
        $this->assertContains('sell', $types);
        $this->assertContains('buy', $types);
    }

    public function test_parse_message_with_z_currency(): void
    {
        $text = "#продам\n📄 Рецепт [III]: Ледяные перчатки стража - 250з\n📙 Последний шанс III - 3000з";
        $result = $this->parser->parse($text);

        $this->assertCount(2, $result['listings']);
        $this->assertEquals(250, $result['listings'][0]['price']);
        $this->assertEquals('gold', $result['listings'][0]['currency']);
    }

    // =========================================================================
    // parseExchangeLines
    // =========================================================================

    public function test_parse_exchange_basic(): void
    {
        $text   = "Мой 🔖 Свиток заточки [III]\nна 🔖 Свиток заточки [IV]";
        $result = $this->parser->parseExchangeLines($text);

        $this->assertCount(1, $result);
        $this->assertEquals('Свиток заточки', $result[0]['give_name']);
        $this->assertEquals('Свиток заточки', $result[0]['want_name']);
    }

    public function test_parse_exchange_from_real_message(): void
    {
        $text = "#обмен Моё-->ваше\n🎽Ледяная кольчуга провидца +7 + 30.000💰-->🎽Ледяная кольчуга провидца х+8";
        // Эта строка не подходит под паттерн "Мой/Моё\nна" — просто не должна упасть
        $result = $this->parser->parseExchangeLines($text);
        $this->assertIsArray($result);
    }

    public function test_parse_returns_empty_for_empty_text(): void
    {
        $result = $this->parser->parse('');
        $this->assertEmpty($result['types']);
        $this->assertEmpty($result['listings']);
    }
}
