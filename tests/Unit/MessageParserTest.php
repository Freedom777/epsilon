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

// =========================================================================
    // extractPrice — "к" (тысячи)
    // =========================================================================

    public function test_extract_price_k_bare(): void
    {
        [$price, $currency] = $this->parser->extractPrice('🎽 T.Rex Crusher Armor [III] 8к');
        $this->assertEquals(8000, $price);
        $this->assertEquals('gold', $currency);
    }

    public function test_extract_price_k_large(): void
    {
        [$price, $currency] = $this->parser->extractPrice('🎽 Кожаный доспех света [IV] 110к');
        $this->assertEquals(110000, $price);
        $this->assertEquals('gold', $currency);
    }

    public function test_extract_price_k_decimal_dot(): void
    {
        [$price, $currency] = $this->parser->extractPrice('📿 Amulet Waves [III+] +3 6.5к');
        $this->assertEquals(6500, $price);
        $this->assertEquals('gold', $currency);
    }

    public function test_extract_price_k_decimal_comma(): void
    {
        [$price, $currency] = $this->parser->extractPrice('🔧Ремкомплект - по 4,5к💰');
        $this->assertEquals(4500, $price);
        $this->assertEquals('gold', $currency);
    }

    public function test_extract_price_k_gold_emoji(): void
    {
        [$price, $currency] = $this->parser->extractPrice('📕 Сила воли IV - 10к💰');
        $this->assertEquals(10000, $price);
        $this->assertEquals('gold', $currency);
    }

    public function test_extract_price_k_gold_emoji_large(): void
    {
        [$price, $currency] = $this->parser->extractPrice('🎽 Доспех [IV] 150к💰');
        $this->assertEquals(150000, $price);
        $this->assertEquals('gold', $currency);
    }

    public function test_extract_price_k_with_dot_decimal_and_emoji(): void
    {
        [$price, $currency] = $this->parser->extractPrice('Amulet waves 5.5к💰');
        $this->assertEquals(5500, $price);
        $this->assertEquals('gold', $currency);
    }

    public function test_parse_product_k_price_name_clean(): void
    {
        $result = $this->parser->parseProductLine('🎽 T.Rex Crusher Armor [III] 8к');
        $this->assertEquals('T.Rex Crusher Armor', $result['name']);
        $this->assertEquals('III', $result['grade']);
        $this->assertEquals(8000, $result['price']);
    }

    // =========================================================================
    // extractPrice — голое число без валюты
    // =========================================================================

    public function test_extract_price_bare_number(): void
    {
        [$price, $currency] = $this->parser->extractPrice('🍞 Корка хлеба [II] 650');
        $this->assertEquals(650, $price);
        $this->assertEquals('gold', $currency);
    }

    public function test_extract_price_bare_after_colon(): void
    {
        [$price, $currency] = $this->parser->extractPrice('🎩 Ледяной капюшон жреца [III+] : 4000');
        $this->assertEquals(4000, $price);
        $this->assertEquals('gold', $currency);
    }

    public function test_extract_price_bare_after_dash(): void
    {
        [$price, $currency] = $this->parser->extractPrice('🧪 Удача торговца - 200');
        $this->assertEquals(200, $price);
        $this->assertEquals('gold', $currency);
    }

    public function test_extract_price_bare_after_equals(): void
    {
        [$price, $currency] = $this->parser->extractPrice('🎐 Амулет оракула [IV] = 350');
        $this->assertEquals(350, $price);
        $this->assertEquals('gold', $currency);
    }

    public function test_parse_product_bare_price_name_clean(): void
    {
        $result = $this->parser->parseProductLine('🎩 Ледяной капюшон жреца [III+] : 4000');
        $this->assertEquals('Ледяной капюшон жреца', $result['name']);
        $this->assertEquals('III+', $result['grade']);
        $this->assertEquals(4000, $result['price']);
    }

    // =========================================================================
    // 🔤-заголовки как разделители секций
    // =========================================================================

    public function test_emoji_header_splits_into_sell_and_buy(): void
    {
        $text = "🔤🔤🔤🔤🔤🔤\n💍 Кольцо [IV] = 180🍪\n🔤🔤🔤🔤🔤\n✴️ Медь = 20💰";
        $result = $this->parser->parse($text);

        $this->assertContains('sell', $result['types']);
        $this->assertContains('buy', $result['types']);

        $types = array_column($result['listings'], 'type');
        $this->assertContains('sell', $types);
        $this->assertContains('buy', $types);
    }

    public function test_emoji_header_detects_trade_section(): void
    {
        $text = "🔤🔤🔤🔤🔤🔤\n🔖 Свиток = 100💰\n🔤🔤🔤🔤🔤\nРассматриваю любые обмены\nМой бутер на ваш пирог";
        $result = $this->parser->parse($text);

        $this->assertContains('sell', $result['types']);
        $this->assertContains('trade', $result['types']);
    }

    public function test_emoji_header_detects_service_section(): void
    {
        $text = "🔤🔤🔤🔤🔤🔤\n🔖 Свиток = 100💰\n🔤🔤🔤🔤🔤🔤\nКрафт ГМ4 | Алхим ГМ4";
        $result = $this->parser->parse($text);

        $this->assertContains('service', $result['types']);
    }

    public function test_emoji_header_buy_section_items_have_buy_type(): void
    {
        $text = "🔤🔤🔤🔤🔤🔤\n💍 Кольцо [IV] = 180🍪\n🔤🔤🔤🔤🔤\nⓂ️ Материя [II] = 250💰";
        $result = $this->parser->parse($text);

        // Материя должна быть buy, не sell
        $buyItems = array_filter($result['listings'], fn($i) => $i['type'] === 'buy');
        $this->assertNotEmpty($buyItems);
        $buyItem = array_values($buyItems)[0];
        $this->assertStringContainsString('Материя', $buyItem['name']);
    }

    public function test_no_types_no_headers_returns_empty(): void
    {
        // Без хэштегов, keywords и 🔤-заголовков — игнорируем
        $result = $this->parser->parse('Просто текст без ничего');
        $this->assertEmpty($result['types']);
        $this->assertEmpty($result['listings']);
    }

    // =========================================================================
    // Декоративные заголовки с пробелами: "К У П Л Ю", "П Р О Д А М"
    // =========================================================================

    public function test_spaced_header_detected_as_type(): void
    {
        $text = "П Р О Д А М\n🔖 Свиток [III] - 1350💰";
        $result = $this->parser->parse($text);
        $this->assertContains('sell', $result['types']);
        $this->assertNotEmpty($result['listings']);
    }

    public function test_spaced_header_not_parsed_as_product(): void
    {
        $text = "#продам\nК У П Л Ю\n🔖 Свиток [III] - 1350💰";
        $result = $this->parser->parse($text);
        // "К У П Л Ю" не должен быть товаром
        foreach ($result['listings'] as $item) {
            $this->assertStringNotContainsString('К У П Л Ю', $item['name']);
        }
    }

    public function test_spaced_header_splits_sections(): void
    {
        $text = "П Р О Д А М\n🔖 Свиток - 100💰\nК У П Л Ю\n✴️ Медь - 20💰";
        $result = $this->parser->parse($text);
        $this->assertContains('sell', $result['types']);
        $this->assertContains('buy', $result['types']);
        $types = array_column($result['listings'], 'type');
        $this->assertContains('sell', $types);
        $this->assertContains('buy', $types);
    }

    // =========================================================================
    // parse — товар на строке с хэштегом
    // =========================================================================

    public function test_parse_product_on_hashtag_line(): void
    {
        $text = "#продам биотоковую пластину";
        $result = $this->parser->parse($text);
        $this->assertContains('sell', $result['types']);
        $this->assertNotEmpty($result['listings']);
        $this->assertStringContainsString('биотоковую пластину', $result['listings'][0]['name']);
    }

    public function test_parse_hashtag_with_decorative_emoji_ignored(): void
    {
        // Декоративные emoji после тега не должны создавать мусорных товаров
        $text = "#Продам 🔤🔤🔤🔤🔤🔤🔤\n🔖 Свиток [III] - 1350💰";
        $result = $this->parser->parse($text);
        // Должен быть только один товар (свиток), а не два
        $this->assertCount(1, $result['listings']);
        $this->assertEquals('Свиток', $result['listings'][0]['name']);
    }

    // =========================================================================
    // cleanName — ведущие предлоги
    // =========================================================================

    public function test_cleanup_leading_preposition_po(): void
    {
        // "по 400💰 за, только набором" — после извлечения цены мусор
        $result = $this->parser->parseProductLine('по 400💰 за, только набором, только оптом');
        $this->assertNull($result);
    }

    public function test_cleanup_noise_obmen(): void
    {
        $result = $this->parser->parseProductLine('обмены на то, что ниже, кселы/талы');
        $this->assertNull($result);
    }

    public function test_cleanup_po_bare_price(): void
    {
        // "по 150💰" — нет имени товара
        $result = $this->parser->parseProductLine('по 150💰');
        $this->assertNull($result);
    }

    // =========================================================================
    // Не обрезаем начало слов на по-/от-/за-
    // =========================================================================

    public function test_cleanup_preserves_potroshitel(): void
    {
        $result = $this->parser->parseProductLine('💟 Потрошитель [Ивент] - 5000💰');
        $this->assertEquals('Потрошитель [Ивент]', $result['name']);
    }

    public function test_cleanup_preserves_povar(): void
    {
        $result = $this->parser->parseProductLine('Повар ГМ4');
        $this->assertNotNull($result);
        $this->assertEquals('Повар ГМ4', $result['name']);
    }

    public function test_cleanup_preserves_podarok(): void
    {
        $result = $this->parser->parseProductLine('📿 Подарок судьбы [III] - 1000💰');
        $this->assertStringContainsString('Подарок судьбы', $result['name']);
    }

    public function test_cleanup_preserves_otrava(): void
    {
        $result = $this->parser->parseProductLine('🧪 Отрава - 500💰');
        $this->assertEquals('Отрава', $result['name']);
    }

    public function test_cleanup_preserves_zakalka(): void
    {
        $result = $this->parser->parseProductLine('💟 Закалка - 3500💰');
        $this->assertEquals('Закалка', $result['name']);
    }

    // =========================================================================
    // Грейд: микс I/l/| → нормализация
    // =========================================================================

    public function test_grade_mixed_IIl_becomes_III(): void
    {
        $result = $this->parser->parseProductLine('🌡 Зелье очищения камня [IIl] 7000 з');
        $this->assertEquals('III', $result['grade']);
        $this->assertEquals('Зелье очищения камня', $result['name']);
    }

    public function test_grade_pipe_becomes_I(): void
    {
        $result = $this->parser->parseProductLine('🔪 Меч [I|l] - 500💰');
        $this->assertEquals('III', $result['grade']);
    }

    public function test_grade_single_l_becomes_I(): void
    {
        $result = $this->parser->parseProductLine('🔖 Свиток [l] - 100💰');
        $this->assertEquals('I', $result['grade']);
    }

    // =========================================================================
    // Цена с пробелом-разделителем тысяч: "6 900" → 6900
    // =========================================================================

    public function test_bare_price_space_thousands(): void
    {
        $result = $this->parser->parseProductLine('Бонус регенерации 7 дней - 6 900');
        $this->assertEquals(6900, $result['price']);
        $this->assertStringContainsString('Бонус регенерации', $result['name']);
    }

    public function test_bare_price_space_thousands_large(): void
    {
        $result = $this->parser->parseProductLine('🌂 Крылья [I] - 18 000');
        $this->assertEquals(18000, $result['price']);
    }

    // =========================================================================
    // Regression — существующие паттерны не сломались
    // =========================================================================

    public function test_regression_z_currency_still_works(): void
    {
        [$price, $currency] = $this->parser->extractPrice('Свиток [III] - 1350з');
        $this->assertEquals(1350, $price);
        $this->assertEquals('gold', $currency);
    }

    public function test_regression_emoji_price_still_works(): void
    {
        [$price] = $this->parser->extractPrice('Свиток - 1350💰');
        $this->assertEquals(1350, $price);
    }

    public function test_regression_cookie_pech_still_works(): void
    {
        [$price, $currency] = $this->parser->extractPrice('Амулет оракула [IV] - 350 печ');
        $this->assertEquals(350, $price);
        $this->assertEquals('cookie', $currency);
    }

    public function test_regression_dot_separator_still_works(): void
    {
        [$price] = $this->parser->extractPrice('Предмет - 19.999💰');
        $this->assertEquals(19999, $price);
    }

    public function test_regression_space_separator_still_works(): void
    {
        [$price] = $this->parser->extractPrice('Ремкомплект - 3 300💰');
        $this->assertEquals(3300, $price);
    }

    public function test_regression_full_sell_message(): void
    {
        $text = "#продам\n🔪 Чекан Маржаны [III+] - 5500💰\n🎩 Ледяной марион провидца [III+] - 6000💰";
        $result = $this->parser->parse($text);
        $this->assertCount(2, $result['listings']);
        $this->assertEquals('Чекан Маржаны', $result['listings'][0]['name']);
        $this->assertEquals(5500, $result['listings'][0]['price']);
    }

    public function test_regression_grade_fake_roman(): void
    {
        $result = $this->parser->parseProductLine('🔖 Свиток заточки [lll] - 66з');
        $this->assertEquals('III', $result['grade']);
    }

    public function test_regression_enhancement_and_durability(): void
    {
        $result = $this->parser->parseProductLine('🎽 Crusher Armor [III] +7 (10/41) - 24000💰');
        $this->assertEquals(7, $result['enhancement']);
        $this->assertEquals(10, $result['durability_current']);
        $this->assertEquals(41, $result['durability_max']);
        $this->assertEquals(24000, $result['price']);
    }

    // =========================================================================
    // PRICE_BARE не ловит заточку +N как цену
    // =========================================================================

    public function test_enhancement_not_caught_as_bare_price(): void
    {
        $result = $this->parser->parseProductLine('🔪 Sledgehammer [III] +10');
        $this->assertEquals('Sledgehammer', $result['name']);
        $this->assertEquals('III', $result['grade']);
        $this->assertEquals(10, $result['enhancement']);
        $this->assertNull($result['price']);
    }

    public function test_enhancement_plus_bare_price_coexist(): void
    {
        // +1 = enhancement, 5500 = bare price
        $result = $this->parser->parseProductLine('🛡 Скутум вьюги [III] +1 5500');
        $this->assertEquals('Скутум вьюги', $result['name']);
        $this->assertEquals(1, $result['enhancement']);
        $this->assertEquals(5500, $result['price']);
    }

    public function test_multiple_buy_sell_tags_split_correctly(): void
    {
        $text = "#куплю\n❇️ Кожа - 21💰\n#продам\n🌂 Крылья [I] - 16500💰\n#куплю\n✴️ Дуб - 21💰";
        $result = $this->parser->parse($text);

        $buyNames  = array_column(array_filter($result['listings'], fn($i) => $i['type'] === 'buy'), 'name');
        $sellNames = array_column(array_filter($result['listings'], fn($i) => $i['type'] === 'sell'), 'name');

        $this->assertContains('Кожа', $buyNames);
        $this->assertContains('Дуб', $buyNames);
        $this->assertNotContains('Кожа', $sellNames);
    }
}
