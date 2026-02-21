<?php

namespace App\Services;

/**
 * MessageParser
 *
 * Парсит текст Telegram-сообщения из торгового чата Epsilion War.
 *
 * Поддерживаемые форматы цен:
 *   5000💰 / 5 000💰 / 5.000💰         — золото
 *   100🍪                               — печеньки
 *   5000з / 5000 з / 5000 злато        — золото (текстовый формат)
 *   100 печ / 100 печеньки             — печеньки (текстовый формат)
 *
 * Поддерживаемые форматы грейда:
 *   [III+] / [III] / [II] / [I] / [IV] / [V]   — обычные
 *   [lll+] / [ll] / [l]                         — с латинскими L вместо I
 *
 * Рецепты:
 *   📄 Рецепт [III]: Ледяные перчатки стража   — название = "Рецепт: Ледяные перчатки стража"
 */
class MessageParser
{
    private const GOLD_SYMBOL   = '💰';
    private const COOKIE_SYMBOL = '🍪';

    // Символьные валюты: 💰 или 🍪
    private const PRICE_SYMBOL_PATTERN = '/(\d[\d\s.]{0,12})\s*(' . self::GOLD_SYMBOL . '|' . self::COOKIE_SYMBOL . ')/u';

    // Текстовые валюты: з, злато, зол (золото); печ, печеньки (cookie)
    private const PRICE_TEXT_GOLD_PATTERN   = '/(\d[\d\s.]{0,12})\s*(?:з(?:лато|ол)?\.?)(?:\b|$)/ui';
    private const PRICE_TEXT_COOKIE_PATTERN = '/(\d[\d\s.]{0,12})\s*(?:печеньк[аиу]?|печ\.?)(?:\b|$)/ui';

    // Грейд: [III+], [lll+], [II], [ll], [I], [l], [IV], [V]
    // Поддерживаем как кириллические/латинские I и L
    private const GRADE_PATTERN = '/\[\s*(III\+|III|II|IV|V|I|lll\+|lll|ll|l)\s*\]/ui';

    // Заточка: +3, +10
    private const ENHANCEMENT_PATTERN = '/(?<![%\d])\+([1-9]|10)(?![\d%])/u';

    // Прочность: (47/47), 47/47
    private const DURABILITY_PATTERN = '/\(?\s*(\d{1,5})\s*\/\s*(\d{1,5})\s*\)?/u';

    // Нормализация латинских l → римские I в грейде
    private const GRADE_NORMALIZE = [
        'lll+' => 'III+',
        'lll'  => 'III',
        'll'   => 'II',
        'l'    => 'I',
    ];

    private array $tagMap;
    private array $keywordMap;

    public function __construct()
    {
        $this->tagMap = config('parser.tag_map', [
            'sell'    => ['#продам', '#продаю', '#продажа', '#sell'],
            'buy'     => ['#куплю', '#скупка', '#скуплю', '#скупаю', '#buy', '#ищу'],
            'trade'   => ['#обмен', '#обменяю', '#меняю', '#мен'],
            'service' => ['#услуги', '#услуга', '#крафтер', '#алхимик', '#заточки', '#свитки', '#найму', '#найм'],
        ]);

        $this->keywordMap = config('parser.keyword_map', [
            'sell'    => ['продам', 'продаю', 'продается', 'продаётся'],
            'buy'     => ['куплю', 'покупаю', 'скупаю'],
            'trade'   => ['обменяю', 'меняю'],
            'service' => ['предлагаю услуги', 'выполню', 'найму'],
        ]);
    }

    // =========================================================================
    // Публичный API
    // =========================================================================

    public function parse(string $text): array
    {
        $result = [
            'types'            => [],
            'listings'         => [],
            'exchanges'        => [],
            'service_listings' => [],
        ];

        if (empty(trim($text))) {
            return $result;
        }

        $types = $this->detectTypes($text);
        $result['types'] = $types;

        if (empty($types)) {
            return $result;
        }

        $sections = $this->splitIntoSections($text);

        foreach ($sections as $sectionType => $sectionText) {
            if ($sectionType === 'sell' || $sectionType === 'buy') {
                $items = $this->parseProductLines($sectionText);
                foreach ($items as $item) {
                    $item['type'] = $sectionType;
                    $result['listings'][] = $item;
                }
            } elseif ($sectionType === 'trade') {
                $exchanges = $this->parseExchangeLines($sectionText);
                $result['exchanges'] = array_merge($result['exchanges'], $exchanges);
            } elseif ($sectionType === 'service') {
                $services = $this->parseServiceLines($sectionText);
                $result['service_listings'] = array_merge($result['service_listings'], $services);
            }
        }

        return $result;
    }

    public function detectTypes(string $text): array
    {
        $textLower = mb_strtolower($text);
        $types     = [];

        foreach ($this->tagMap as $type => $tags) {
            foreach ($tags as $tag) {
                if (mb_strpos($textLower, mb_strtolower($tag)) !== false) {
                    $types[] = $type;
                    break;
                }
            }
        }

        if (empty($types)) {
            foreach (explode("\n", $text) as $line) {
                $lineLower = mb_strtolower(trim($line));
                foreach ($this->keywordMap as $type => $keywords) {
                    foreach ($keywords as $keyword) {
                        if (mb_strpos($lineLower, $keyword) === 0) {
                            $types[] = $type;
                            break 2;
                        }
                    }
                }
            }
        }

        return array_unique($types);
    }

    public function parseProductLines(string $text): array
    {
        $items = [];

        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if (empty($line) || preg_match('/^#\w/u', $line)) {
                continue;
            }

            // Разбиваем строку по запятой перед иконкой или перед 📄/📒/📗 и т.д.
            $sublines = preg_split(
                '/,\s*(?=[\p{So}\p{Sk}\p{Sm}\x{1F000}-\x{1FFFF}\x{2600}-\x{27FF}\x{2300}-\x{23FF}])/u',
                $line
            );

            foreach ($sublines as $subline) {
                $subline = trim($subline);
                if (empty($subline)) continue;

                $item = $this->parseProductLine($subline);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * Парсим одну строку товара.
     *
     * Примеры:
     *   🔖 Безопасный свиток заточки [III] - 1350💰
     *   🔖 Безопасный свиток заточки [III] - 1350 з
     *   🎽 Crusher Armor [III] +7 (10/41) - 24000💰
     *   📿 Amulet Waves [III+] +5 -- 10.000💰
     *   📄 Рецепт [III]: Ледяные перчатки стража - 250з
     *   🎨 Внешний вид: Орк-призрак - 90 печ
     */
    public function parseProductLine(string $line): ?array
    {
        // 1. Извлекаем цену — сначала символьные валюты, потом текстовые
        [$price, $currency, $line] = $this->extractPrice($line);

        // 2. Нормализуем грейд (латинские l → римские I) и извлекаем
        $line  = $this->normalizeFakeRomanGrade($line);
        $grade = null;
        if (preg_match(self::GRADE_PATTERN, $line, $m)) {
            $grade = $this->normalizeGrade($m[1]);
            $line  = preg_replace(self::GRADE_PATTERN, '', $line, 1);
        }

        // 3. Заточка +N
        $enhancement = null;
        if (preg_match(self::ENHANCEMENT_PATTERN, $line, $m)) {
            $enhancement = (int) $m[1];
            $line        = preg_replace(self::ENHANCEMENT_PATTERN, '', $line, 1);
        }

        // 4. Прочность (47/47)
        $durabilityCurrent = null;
        $durabilityMax     = null;
        if (preg_match(self::DURABILITY_PATTERN, $line, $m)) {
            $durabilityCurrent = (int) $m[1];
            $durabilityMax     = (int) $m[2];
            $line              = preg_replace(self::DURABILITY_PATTERN, '', $line, 1);
        }

        // 5. Иконка в начале
        $icon = null;
        $line = trim($line);
        if (preg_match('/^([\p{So}\p{Sk}\p{Sm}\x{1F000}-\x{1FFFF}\x{2600}-\x{27FF}\x{2300}-\x{23FF}]+)\s*/u', $line, $m)) {
            $icon = trim($m[1]);
            $line = trim(mb_substr($line, mb_strlen($m[0])));
        }

        // 6. Рецепт: убираем "[грейд]:" между "Рецепт" и названием
        //    "Рецепт [III]: Ледяные перчатки" → "Рецепт: Ледяные перчатки"
        $line = preg_replace('/^(рецепт)\s*(?:\[[^\]]+\])?\s*:/ui', '$1:', $line);

        // 7. Финальная очистка
        $name = $this->cleanName($line);

        if (mb_strlen($name) < 2) {
            return null;
        }

        return [
            'icon'               => $icon,
            'name'               => $name,
            'grade'              => $grade,
            'enhancement'        => $enhancement,
            'durability_current' => $durabilityCurrent,
            'durability_max'     => $durabilityMax,
            'price'              => $price,
            'currency'           => $currency,
        ];
    }

    public function parseExchangeLines(string $text): array
    {
        $exchanges = [];
        $linesArr  = array_values(array_filter(array_map('trim', explode("\n", $text))));
        $count     = count($linesArr);

        $i = 0;
        while ($i < $count) {
            $line = $linesArr[$i];

            if (preg_match('/^мо[йияе]\s+(.+)/ui', $line, $mGive)) {
                $givePart = trim($mGive[1]);
                $giveQty  = 1;

                if (preg_match('/(\d+)\s*шт/ui', $givePart, $mq)) {
                    $giveQty  = (int) $mq[1];
                    $givePart = trim(preg_replace('/[-–—]?\s*\d+\s*шт/ui', '', $givePart));
                }

                [$giveIcon, $giveName] = $this->extractIconAndName($givePart);

                $j = $i + 1;
                if ($j < $count && preg_match('/^на\s+(.+)/ui', $linesArr[$j], $mWant)) {
                    $wantPart = trim($mWant[1]);
                    $wantQty  = 1;

                    if (preg_match('/(\d+)\s*шт/ui', $wantPart, $mq)) {
                        $wantQty  = (int) $mq[1];
                        $wantPart = trim(preg_replace('/[-–—]?\s*\d+\s*шт/ui', '', $wantPart));
                    }

                    $surcharge          = null;
                    $surchargeCurrency  = null;
                    $surchargeDirection = null;

                    [, $wantPartCurrency, $wantPartClean] = $this->extractPrice($wantPart);
                    if ($wantPartCurrency !== 'gold' || strpos($wantPart, self::GOLD_SYMBOL) !== false
                        || preg_match('/\d/', $wantPart)) {
                        // Есть цена в want — это доплата
                        [$surcharge, $surchargeCurrency] = $this->extractPriceRaw($wantPart);
                        if ($surcharge) {
                            $wantPart           = $wantPartClean;
                            $surchargeDirection = preg_match('/с\s+вашей|вашей\s+доплат/ui', $linesArr[$j])
                                ? 'them' : 'me';
                        }
                    }

                    [$wantIcon, $wantName] = $this->extractIconAndName($wantPart);

                    if ($giveName && $wantName) {
                        $exchanges[] = [
                            'give_icon'           => $giveIcon,
                            'give_name'           => $giveName,
                            'give_qty'            => $giveQty,
                            'want_icon'           => $wantIcon,
                            'want_name'           => $wantName,
                            'want_qty'            => $wantQty,
                            'surcharge'           => $surcharge,
                            'surcharge_currency'  => $surchargeCurrency,
                            'surcharge_direction' => $surchargeDirection,
                        ];
                    }

                    $i = $j + 1;
                    continue;
                }
            }

            $i++;
        }

        return $exchanges;
    }

    public function parseServiceLines(string $text): array
    {
        $services    = [];
        $sectionType = 'offer';
        $textLower   = mb_strtolower($text);

        foreach (($this->tagMap['service'] ?? []) as $tag) {
            if (in_array(mb_strtolower($tag), ['#найму', '#найм']) &&
                mb_strpos($textLower, mb_strtolower($tag)) !== false) {
                $sectionType = 'wanted';
                break;
            }
        }

        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if (empty($line) || preg_match('/^#\w/u', $line)) {
                continue;
            }

            $item = $this->parseProductLine($line);
            if ($item !== null) {
                $services[] = [
                    'type'        => $sectionType,
                    'icon'        => $item['icon'],
                    'name'        => $item['name'],
                    'price'       => $item['price'],
                    'currency'    => $item['currency'],
                    'description' => $line,
                ];
            }
        }

        return $services;
    }

    // =========================================================================
    // Извлечение цены (публичный хелпер для тестов)
    // =========================================================================

    /**
     * @return array [price|null, currency, cleaned_line]
     */
    public function extractPrice(string $line): array
    {
        // Символьные: 5000💰 / 100🍪
        if (preg_match(self::PRICE_SYMBOL_PATTERN, $line, $m)) {
            $price    = $this->parseNumber($m[1]);
            $currency = $m[2] === self::GOLD_SYMBOL ? 'gold' : 'cookie';
            $line     = trim(preg_replace(self::PRICE_SYMBOL_PATTERN, '', $line, 1));
            return [$price, $currency, $line];
        }

        // Текстовые cookie: 100 печ
        if (preg_match(self::PRICE_TEXT_COOKIE_PATTERN, $line, $m)) {
            $price = $this->parseNumber($m[1]);
            $line  = trim(preg_replace(self::PRICE_TEXT_COOKIE_PATTERN, '', $line, 1));
            return [$price, 'cookie', $line];
        }

        // Текстовые gold: 5000з / 5000 злато
        if (preg_match(self::PRICE_TEXT_GOLD_PATTERN, $line, $m)) {
            $price = $this->parseNumber($m[1]);
            $line  = trim(preg_replace(self::PRICE_TEXT_GOLD_PATTERN, '', $line, 1));
            return [$price, 'gold', $line];
        }

        return [null, 'gold', $line];
    }

    // =========================================================================
    // Приватные хелперы
    // =========================================================================

    /**
     * Вернуть только цену и валюту без изменения строки.
     */
    private function extractPriceRaw(string $line): array
    {
        [$price, $currency] = $this->extractPrice($line);
        return [$price, $currency];
    }

    /**
     * Парсим число: убираем пробелы и точки как разделители тысяч.
     * "19.999" → 19999, "3 300" → 3300, "333.333" → 333333
     */
    private function parseNumber(string $raw): int
    {
        // Убираем пробелы и точки (разделители тысяч)
        return (int) preg_replace('/[\s.]+/', '', $raw);
    }

    /**
     * Нормализуем латинские "l" в квадратных скобках в римские "I".
     * "[lll+]" → "[III+]", "[ll]" → "[II]"
     */
    private function normalizeFakeRomanGrade(string $line): string
    {
        return preg_replace_callback('/\[\s*(l{1,3}\+?)\s*\]/ui', function ($m) {
            $key = mb_strtolower($m[1]);
            return '[' . (self::GRADE_NORMALIZE[$key] ?? mb_strtoupper($m[1])) . ']';
        }, $line);
    }

    /**
     * Нормализуем строку грейда к верхнему регистру.
     */
    private function normalizeGrade(string $grade): string
    {
        $lower = mb_strtolower(trim($grade));
        return self::GRADE_NORMALIZE[$lower] ?? mb_strtoupper($grade);
    }

    private function splitIntoSections(string $text): array
    {
        $sections = [];
        $allTags  = [];

        foreach ($this->tagMap as $type => $tags) {
            foreach ($tags as $tag) {
                $allTags[] = ['tag' => $tag, 'type' => $type];
            }
        }

        $textLower = mb_strtolower($text);
        $found     = [];

        foreach ($allTags as $tagInfo) {
            $pos = mb_strpos($textLower, mb_strtolower($tagInfo['tag']));
            if ($pos !== false) {
                $found[] = ['pos' => $pos, 'type' => $tagInfo['type']];
            }
        }

        if (empty($found)) {
            $types = $this->detectTypes($text);
            if (!empty($types)) {
                $sections[$types[0]] = $text;
            }
            return $sections;
        }

        usort($found, fn($a, $b) => $a['pos'] <=> $b['pos']);

        for ($i = 0; $i < count($found); $i++) {
            $start       = $found[$i]['pos'];
            $end         = $found[$i + 1]['pos'] ?? mb_strlen($text);
            $sectionText = mb_substr($text, $start, $end - $start);
            $type        = $found[$i]['type'];

            $sections[$type] = isset($sections[$type])
                ? $sections[$type] . "\n" . $sectionText
                : $sectionText;
        }

        return $sections;
    }

    private function extractIconAndName(string $text): array
    {
        $text = $this->cleanName($text);
        $icon = null;
        $name = $text;

        if (preg_match('/^([\p{So}\p{Sk}\p{Sm}\x{1F000}-\x{1FFFF}\x{2600}-\x{27FF}\x{2300}-\x{23FF}]+)\s*/u', $text, $m)) {
            $icon = trim($m[1]);
            $name = trim(mb_substr($text, mb_strlen($m[0])));
        }

        $name = $this->cleanName($name);

        return [$icon ?: null, $name ?: null];
    }

    private function cleanName(string $name): string
    {
        /*
        // /шт и \шт в конце
        $name = preg_replace('/\s*[\/\\\\]?\s*шт\s*$/ui', '', $name);

        // Хвостовой мусор: +, =, /, -, :, –, —
        $name = rtrim($name, " \t+-=/:–—\\|,.");

        // Ведущий мусор
        $name = ltrim($name, " \t-–—:.,");

        // Множественные пробелы
        $name = preg_replace('/\s{2,}/', ' ', $name);

        return trim($name);
        */
        // Убираем количество: 30шт, 30 шт, /шт, \шт
        $name = preg_replace('/\s*\d+\s*шт\.?\s*|[\/\\\\]\s*шт\.?\s*/ui', '', $name);

        // Убираем хвостовые предлоги-мусор: "по", "от", "за"
        $name = preg_replace('/\s+(по|от|за)(\s+.*)?$/ui', '', $name);

        // Хвостовой мусор: +, =, /, -, :, –, —
        $name = preg_replace('/[\s\t+\-=\/:–—\\\\|,.]+$/u', '', $name);
        $name = preg_replace('/^[\s\t\-–—:.,]+/u', '', $name);

        // Ведущий мусор
        $name = ltrim($name, " \t-–—:.,");

        // Множественные пробелы
        $name = preg_replace('/\s{2,}/', ' ', $name);

        return trim($name);
    }
}
