<?php

namespace App\Services;

/**
 * MessageParser
 *
 * Парсит текст Telegram-сообщения из торгового чата.
 * Возвращает структурированный массив объявлений.
 *
 * Формат возвращаемых данных:
 * [
 *   'types'    => ['sell', 'buy', 'trade', 'service'],
 *   'listings' => [
 *     [
 *       'type'               => 'sell',       // buy | sell
 *       'icon'               => '🔖',
 *       'name'               => 'Безопасный свиток заточки', // чистое название без грейда/заточки/прочности
 *       'grade'              => 'III',         // I|II|III|III+|IV|V|null
 *       'enhancement'        => 3,             // 1..10 | null
 *       'durability_current' => 47,            // null если не указана
 *       'durability_max'     => 47,            // null если не указана
 *       'price'              => 1350,
 *       'currency'           => 'gold',        // gold | cookie
 *       'quantity'           => null,
 *     ],
 *   ],
 *   'exchanges' => [...],
 *   'service_listings' => [...],
 * ]
 */
class MessageParser
{
    private const GOLD_SYMBOL   = '💰';
    private const COOKIE_SYMBOL = '🍪';

    // Грейд: [III+], [III], [II], [IV], [V], [I]
    private const GRADE_PATTERN = '/\[\s*(III\+|III|II|IV|V|I)\s*\]/ui';

    // Заточка: +3, +10 (но не +100%, не в начале строки после цены)
    private const ENHANCEMENT_PATTERN = '/(?<![%\d])\+([1-9]|10)(?![\d%])/u';

    // Прочность: (47/47), 47/47, (60/60)
    private const DURABILITY_PATTERN = '/\(?\s*(\d{1,5})\s*\/\s*(\d{1,5})\s*\)?/u';

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
        $types = [];

        foreach ($this->tagMap as $type => $tags) {
            foreach ($tags as $tag) {
                if (mb_strpos($textLower, mb_strtolower($tag)) !== false) {
                    $types[] = $type;
                    break;
                }
            }
        }

        if (empty($types)) {
            $lines = explode("\n", $text);
            foreach ($lines as $line) {
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

            $item = $this->parseProductLine($line);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Парсим одну строку товара.
     *
     * Примеры:
     *   🔖 Безопасный свиток заточки [III] - 1350💰
     *   📿 Amulet Of Sea Water +3 [III+] (47/47) - 5000💰
     *   🥩 Кусок мяса - - 358шт - 75💰
     *   🔪 Эспадон Маржаны [III+] +4 - 16000💰
     *   📄 Рецепт[IV]:🗡 Кольцо ярости бездны - 3000💰
     */
    public function parseProductLine(string $line): ?array
    {
        // 1. Извлекаем цену (число + символ валюты)
        $price    = null;
        $currency = 'gold';

        $pricePattern = '/(\d[\d\s]{0,12})\s*(' . self::GOLD_SYMBOL . '|' . self::COOKIE_SYMBOL . ')/u';
        if (preg_match($pricePattern, $line, $m)) {
            $price    = (int) preg_replace('/\s+/', '', $m[1]);
            $currency = $m[2] === self::GOLD_SYMBOL ? 'gold' : 'cookie';
            $line     = trim(preg_replace($pricePattern, '', $line, 1));
        }

        // 2. Извлекаем грейд [III+], [III], [II], [IV], [V], [I]
        $grade = null;
        if (preg_match(self::GRADE_PATTERN, $line, $m)) {
            $grade = mb_strtoupper(trim($m[1]));
            $line  = preg_replace(self::GRADE_PATTERN, '', $line, 1);
        }

        // 3. Извлекаем заточку +N (только целые числа 1-10, не процент)
        $enhancement = null;
        if (preg_match(self::ENHANCEMENT_PATTERN, $line, $m)) {
            $enhancement = (int) $m[1];
            $line        = preg_replace(self::ENHANCEMENT_PATTERN, '', $line, 1);
        }

        // 4. Извлекаем прочность (47/47) или 60/60
        $durabilityCurrent = null;
        $durabilityMax     = null;
        if (preg_match(self::DURABILITY_PATTERN, $line, $m)) {
            $durabilityCurrent = (int) $m[1];
            $durabilityMax     = (int) $m[2];
            $line              = preg_replace(self::DURABILITY_PATTERN, '', $line, 1);
        }

        // 5. Извлекаем количество (Nшт, N шт, с двойным дефисом или без)
        //    Паттерн: необязательные дефисы/пробелы + число + шт
        $quantity = null;
        if (preg_match('/(?:[-–—\s]+)?(\d+)\s*шт/ui', $line, $m)) {
            $quantity = (int) $m[1];
            $line     = preg_replace('/(?:[-–—\s]+)?\d+\s*шт/ui', '', $line, 1);
        }

        // 6. Извлекаем иконку (один или несколько эмодзи в начале)
        $icon = null;
        $line = trim($line);
        if (preg_match('/^([\p{So}\p{Sk}\p{Sm}\x{1F000}-\x{1FFFF}\x{2600}-\x{27FF}\x{2300}-\x{23FF}]+)\s*/u', $line, $m)) {
            $icon = trim($m[1]);
            $line = trim(mb_substr($line, mb_strlen($m[0])));
        }

        // 7. Финальная очистка названия
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
            'quantity'           => $quantity,
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

                    $pricePattern = '/(\d[\d\s]*)\s*(' . self::GOLD_SYMBOL . '|' . self::COOKIE_SYMBOL . ')/u';
                    if (preg_match($pricePattern, $wantPart, $ms)) {
                        $surcharge          = (int) preg_replace('/\s+/', '', $ms[1]);
                        $surchargeCurrency  = $ms[2] === self::GOLD_SYMBOL ? 'gold' : 'cookie';
                        $wantPart           = trim(preg_replace($pricePattern, '', $wantPart));
                        $surchargeDirection = preg_match('/с\s+вашей|вашей\s+доплат/ui', $linesArr[$j])
                            ? 'them'
                            : 'me';
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

    /**
     * Хелпер для тестов.
     */
    public function extractPrice(string $text): ?array
    {
        $pattern = '/(\d[\d\s]*)\s*(' . self::GOLD_SYMBOL . '|' . self::COOKIE_SYMBOL . ')/u';
        if (preg_match($pattern, $text, $m)) {
            return [
                'price'    => (int) preg_replace('/\s+/', '', $m[1]),
                'currency' => $m[2] === self::GOLD_SYMBOL ? 'gold' : 'cookie',
            ];
        }
        return null;
    }

    // =========================================================================
    // Приватные хелперы
    // =========================================================================

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

    /**
     * Очистка названия товара:
     * - Убираем ведущие и хвостовые разделители и мусорные символы
     * - Убираем /шт, \шт в конце
     * - Убираем +, =, / в конце
     * - Убираем лишние пробелы
     */
    private function cleanName(string $name): string
    {
        // Убираем /шт и \шт в конце (до trim)
        $name = preg_replace('/\s*[\/\\\\]?\s*шт\s*$/ui', '', $name);

        // Убираем хвостовые мусорные символы
        $name = rtrim($name, " \t+-=/:–—\\|,.");

        // Убираем ведущие разделители и пробелы
        $name = ltrim($name, " \t-–—:.,");

        // Убираем двойные и более пробелы
        $name = preg_replace('/\s{2,}/', ' ', $name);

        return trim($name);
    }
}
