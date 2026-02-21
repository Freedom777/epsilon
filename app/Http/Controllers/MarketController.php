<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Item;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MarketController extends Controller
{
    // Порядок и иконки вкладок
    private const TAB_CONFIG = [
        // Экипировка
        'оружие'      => ['label' => 'Оружие',      'icon' => '⚔️'],
        'доспех'      => ['label' => 'Доспехи',     'icon' => '🛡'],
        'шлем'        => ['label' => 'Шлемы',       'icon' => '⛑'],
        'перчатки'    => ['label' => 'Перчатки',    'icon' => '🥊'],
        'сапоги'      => ['label' => 'Сапоги',      'icon' => '🥾'],
        'кольцо'      => ['label' => 'Кольца',      'icon' => '💍'],
        'колье'       => ['label' => 'Колье',       'icon' => '📿'],
        'аксессуар'   => ['label' => 'Аксессуары',  'icon' => '🌂'],
        'талисман'    => ['label' => 'Талисманы',   'icon' => '🔮'],
        'реликвия'    => ['label' => 'Реликвии',    'icon' => '🏺'],
        'инструмент'  => ['label' => 'Инструменты', 'icon' => '🔧'],
        'щит'         => ['label' => 'Щиты',        'icon' => '🛡'],
        // Расходники
        'зелье'       => ['label' => 'Зелья',       'icon' => '🧪'],
        'свиток'      => ['label' => 'Свитки',      'icon' => '📜'],
        'еда'         => ['label' => 'Еда',         'icon' => '🍖'],
        'талант'      => ['label' => 'Таланты',     'icon' => '✨'],
        'книга'       => ['label' => 'Книги',       'icon' => '📗'],
        'рецепт'      => ['label' => 'Рецепты',     'icon' => '📄'],
        'чертеж'      => ['label' => 'Чертежи',     'icon' => '📐'],
        'материал'    => ['label' => 'Материалы',   'icon' => '🪨'],
        'контейнер'   => ['label' => 'Контейнеры',  'icon' => '📦'],
        'внешний вид' => ['label' => 'Внешний вид', 'icon' => '🎨'],
        'валюта'      => ['label' => 'Валюта',      'icon' => '💰'],
        'премиум'     => ['label' => 'Премиум',     'icon' => '👑'],
        'документ'    => ['label' => 'Документы',   'icon' => '📋'],
        'ивент'       => ['label' => 'Ивент',       'icon' => '🎉'],
        'квест'       => ['label' => 'Квест',       'icon' => '📍'],
        'прочее'      => ['label' => 'Прочее',      'icon' => '🔹'],
    ];

    /**
     * GET /api/market
     *
     * Параметры:
     *   ?format=json|html          — формат ответа (default: json)
     *   ?currency=gold|cookie      — фильтр по валюте (default: все)
     *   ?asset_id=1,2,3            — фильтр по ID расходников
     *   ?item_id=1,2,3             — фильтр по ID экипировки
     *   ?type=зелье                — фильтр по типу (для json)
     *   ?tab=зелье                 — активная вкладка (для html)
     *   ?days=30                   — за сколько дней (default: 30)
     */
    public function index(Request $request): JsonResponse|Response
    {
        $format     = $request->string('format', 'json')->value();
        $currency   = $request->string('currency')->value() ?: null;
        $days       = $request->integer('days', config('parser.fetch.days', 30));
        $typeFilter = $request->string('type')->value() ?: null;

        $assetIds = $this->parseIdList($request->string('asset_id')->value());
        $itemIds  = $this->parseIdList($request->string('item_id')->value());

        $data = $this->buildMarketData($currency, $assetIds, $itemIds, $days);

        if ($format === 'html') {
            $activeTab = $request->string('tab')->value() ?: null;
            return response($this->renderHtml($data, $currency, $days, $activeTab))
                ->header('Content-Type', 'text/html; charset=utf-8');
        }

        if ($typeFilter) {
            $data = array_values(array_filter($data, fn($row) => $row['type'] === $typeFilter));
        }

        return response()->json([
            'meta' => [
                'days'      => $days,
                'currency'  => $currency ?? 'all',
                'total'     => count($data),
                'generated' => now()->toIso8601String(),
            ],
            'data' => $data,
        ]);
    }

    // =========================================================================
    // Построение данных
    // =========================================================================

    private function buildMarketData(
        ?string $currency,
        ?array  $assetIds,
        ?array  $itemIds,
        int     $days
    ): array {
        $since = now()->subDays($days);

        $buyPricesAsset  = $this->getAggregatePrices('buy',  'asset_id', $currency, $assetIds, $since);
        $sellPricesAsset = $this->getAggregatePrices('sell', 'asset_id', $currency, $assetIds, $since);
        $buyPricesItem   = $this->getAggregatePrices('buy',  'item_id',  $currency, $itemIds,  $since);
        $sellPricesItem  = $this->getAggregatePrices('sell', 'item_id',  $currency, $itemIds,  $since);

        $allAssetIds = $buyPricesAsset->keys()->merge($sellPricesAsset->keys())->unique()->values();
        $allItemIds  = $buyPricesItem->keys()->merge($sellPricesItem->keys())->unique()->values();

        $result = [];

        foreach ($allAssetIds as $assetId) {
            $asset = Asset::find($assetId);
            if (!$asset) continue;

            $row = [
                'asset_id'     => $asset->id,
                'item_id'      => null,
                'product_name' => $asset->title,
                'description'  => $asset->description,
                'grade'        => $asset->grade,
                'type'         => $asset->type,
                'currency'     => $currency ?? 'gold',
                'buy'          => null,
                'sell'         => null,
            ];

            if ($buyPricesAsset->has($assetId)) {
                $row['buy'] = $this->getBestListing('asset_id', $assetId, 'buy', $currency, $buyPricesAsset[$assetId], $since);
            }
            if ($sellPricesAsset->has($assetId)) {
                $row['sell'] = $this->getBestListing('asset_id', $assetId, 'sell', $currency, $sellPricesAsset[$assetId], $since);
            }

            $result[] = $row;
        }

        foreach ($allItemIds as $itemId) {
            $item = Item::find($itemId);
            if (!$item) continue;

            $row = [
                'asset_id'     => null,
                'item_id'      => $item->id,
                'product_name' => $item->title,
                'description'  => $item->description,
                'grade'        => $item->grade,
                'type'         => $item->type,
                'currency'     => $currency ?? 'gold',
                'buy'          => null,
                'sell'         => null,
            ];

            if ($buyPricesItem->has($itemId)) {
                $row['buy'] = $this->getBestListing('item_id', $itemId, 'buy', $currency, $buyPricesItem[$itemId], $since);
            }
            if ($sellPricesItem->has($itemId)) {
                $row['sell'] = $this->getBestListing('item_id', $itemId, 'sell', $currency, $sellPricesItem[$itemId], $since);
            }

            $result[] = $row;
        }

        usort($result, fn($a, $b) => strcmp($a['product_name'] ?? '', $b['product_name'] ?? ''));

        return $result;
    }

    private function getAggregatePrices(
        string  $type,
        string  $column,
        ?string $currency,
        ?array  $ids,
        \Carbon\Carbon $since
    ): \Illuminate\Support\Collection {
        $aggregate = $type === 'buy' ? 'MAX' : 'MIN';

        $query = DB::table('listings')
            ->whereNotNull($column)
            ->where('type', $type)
            ->where('status', '!=', 'invalid')
            ->whereNotNull('price')
            ->where('posted_at', '>=', $since)
            ->select([$column, DB::raw("{$aggregate}(price) as best_price")])
            ->groupBy($column);

        if ($currency) $query->where('currency', $currency);
        if ($ids)      $query->whereIn($column, $ids);

        return $query->pluck('best_price', $column);
    }

    private function getBestListing(
        string  $column,
        int     $id,
        string  $type,
        ?string $currency,
        int     $price,
        \Carbon\Carbon $since
    ): ?array {
        $query = Listing::with(['tgUser', 'tgMessage'])
            ->where($column, $id)
            ->where('type', $type)
            ->where('price', $price)
            ->where('status', '!=', 'invalid')
            ->where('posted_at', '>=', $since);

        if ($currency) $query->where('currency', $currency);

        $listing = $query->orderByDesc('posted_at')->first();
        if (!$listing) return null;

        $user        = $listing->tgUser;
        $message     = $listing->tgMessage;
        $userDisplay = $user?->display_name ?? $user?->username ?? 'Неизвестен';
        $userLink    = $user?->username ? 'https://t.me/' . $user->username : null;

        return [
            'price'        => $price,
            'currency'     => $listing->currency,
            'posted_at'    => $listing->posted_at?->toIso8601String(),
            'tg_link'      => $message?->tg_link,
            'user_display' => $userDisplay,
            'user_tg_link' => $userLink,
            'status'       => $listing->status,
        ];
    }

    // =========================================================================
    // HTML рендер с вкладками
    // =========================================================================

    private function renderHtml(array $data, ?string $currency, int $days, ?string $activeTab): string
    {
        $grouped = [];
        foreach ($data as $row) {
            $type = $row['type'] ?? 'прочее';
            $grouped[$type][] = $row;
        }

        if (empty($grouped)) {
            return $this->renderEmpty($days);
        }

        $availableTabs = array_keys($grouped);

        // Первая вкладка по порядку TAB_CONFIG
        $defaultTab = null;
        foreach (self::TAB_CONFIG as $type => $_) {
            if (in_array($type, $availableTabs)) {
                $defaultTab = $type;
                break;
            }
        }
        $defaultTab ??= $availableTabs[0];

        // Строим кнопки вкладок
        $tabsHtml = '';
        foreach (self::TAB_CONFIG as $type => $config) {
            if (!in_array($type, $availableTabs)) continue;

            $count    = count($grouped[$type]);
            $tabId = 'tab-' . md5($type);
            $tabsHtml .= "<button class=\"tab\" data-tab=\"{$tabId}\" onclick=\"switchTab('{$tabId}')\">"
                . "{$config['icon']} {$config['label']}"
                . " <span class=\"count\">{$count}</span>"
                . "</button>";
        }

        // Строим таблицы для каждой вкладки
        $tablesHtml = '';
        foreach (self::TAB_CONFIG as $type => $config) {
            if (!in_array($type, $availableTabs)) continue;

            $tabId = 'tab-' . md5($type);
            $rows  = '';

            foreach ($grouped[$type] as $item) {
                $desc     = !empty($item['description']) ? ' title="' . e(strip_tags($item['description'])) . '"' : '';
                $name     = "<span{$desc}>" . e($item['product_name']) . "</span>";
                $buyCell  = $this->formatPriceCell($item['buy']);
                $sellCell = $this->formatPriceCell($item['sell']);
                $rows    .= "<tr><td>{$name}</td>{$buyCell}{$sellCell}</tr>\n";
            }

            $tablesHtml .= "<div class=\"tab-content\" id=\"{$tabId}\">"
                . "<table><thead><tr>"
                . "<th>{$config['icon']} {$config['label']}</th>"
                . "<th>📈 Макс. цена покупки</th>"
                . "<th>📉 Мин. цена продажи</th>"
                . "</tr></thead><tbody>{$rows}</tbody></table>"
                . "</div>";
        }

        $defaultTabId   = 'tab-' . md5($defaultTab);
        $currencyLabel  = match ($currency) {
            'gold'   => '💰 Золото',
            'cookie' => '🍪 Печеньки',
            default  => 'Все валюты',
        };
        $now = now()->format('d.m.Y H:i');

        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Рынок Epsilion War</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; padding: 16px; background: #1a1a2e; color: #eee; }
        h1 { color: #f0c040; margin-bottom: 8px; font-size: 1.4em; }
        .meta { color: #aaa; margin-bottom: 14px; font-size: 0.82em; }

        .tabs { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 14px; }
        .tab {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 11px; border-radius: 4px;
            background: #16213e; color: #aaa;
            border: 1px solid #2a2a3e; font-size: 0.82em;
            cursor: pointer; transition: background 0.15s;
            white-space: nowrap;
        }
        .tab:hover { background: #1a2a50; color: #ddd; }
        .tab.active { background: #0f3460; color: #f0c040; border-color: #f0c040; }
        .count { background: #2a2a3e; border-radius: 10px; padding: 1px 6px; font-size: 0.78em; color: #888; }
        .tab.active .count { background: #1a3a70; color: #f0c040; }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        table { width: 100%; border-collapse: collapse; }
        th { background: #0f3460; color: #f0c040; padding: 9px 10px; text-align: left; font-size: 0.9em; }
        td { padding: 7px 10px; border-bottom: 1px solid #222; vertical-align: top; font-size: 0.88em; }
        tr:hover td { background: #1a2a50; }
        td span[title] { cursor: help; border-bottom: 1px dotted #555; }
        .price { font-weight: bold; color: #f0c040; }
        .user a { color: #7ec8e3; text-decoration: none; }
        .user a:hover { text-decoration: underline; }
        .date a, .date span { color: #777; font-size: 0.85em; text-decoration: none; }
        .date a:hover { text-decoration: underline; }
        .suspicious { color: #ff9900; }
        .no-data { color: #444; font-style: italic; text-align: center; }
    </style>
</head>
<body>
    <h1>🏪 Рынок Epsilion War</h1>
    <div class="meta">
        Данные за последние {$days} дней &nbsp;|&nbsp; {$currencyLabel} &nbsp;|&nbsp; Обновлено: {$now}
    </div>

    <div class="tabs">{$tabsHtml}</div>

    {$tablesHtml}

    <script>
        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            document.querySelector('[data-tab="' + tabId + '"]').classList.add('active');
            localStorage.setItem('market_tab', tabId);
        }

        // Восстанавливаем последнюю вкладку или открываем дефолтную
        const saved = localStorage.getItem('market_tab');
        const target = saved && document.getElementById(saved) ? saved : '{$defaultTabId}';
        switchTab(target);
    </script>
</body>
</html>
HTML;
    }

    private function renderEmpty(int $days): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><title>Рынок Epsilion War</title></head>
<body style="background:#1a1a2e;color:#eee;padding:20px;font-family:Arial">
    <h1 style="color:#f0c040">🏪 Рынок Epsilion War</h1>
    <p style="color:#aaa;margin-top:16px">Нет данных за последние {$days} дней.</p>
</body>
</html>
HTML;
    }

    private function formatPriceCell(?array $data): string
    {
        if (!$data) {
            return '<td class="no-data">—</td>';
        }

        $currencySymbol = $data['currency'] === 'cookie' ? '🍪' : '💰';
        $price          = number_format($data['price'], 0, '.', ' ');
        $statusAttr     = $data['status'] === 'suspicious'
            ? ' class="suspicious" title="Подозрительная цена"'
            : '';

        $userHtml = $data['user_tg_link']
            ? '<a href="' . e($data['user_tg_link']) . '" target="_blank">' . e($data['user_display']) . '</a>'
            : '<span>' . e($data['user_display'] ?? '') . '</span>';

        $dateFormatted = $data['posted_at']
            ? date('d.m.Y H:i', strtotime($data['posted_at']))
            : '';

        $dateHtml = $data['tg_link']
            ? '<a href="' . e($data['tg_link']) . '" target="_blank">' . $dateFormatted . '</a>'
            : '<span>' . $dateFormatted . '</span>';

        return "<td>
            <span class=\"price\"{$statusAttr}>{$price} {$currencySymbol}</span><br>
            <span class=\"user\">{$userHtml}</span><br>
            <span class=\"date\">{$dateHtml}</span>
        </td>";
    }

    // =========================================================================
    // Хелперы
    // =========================================================================

    private function parseIdList(string $value): ?array
    {
        if (blank($value)) return null;

        $ids = array_filter(array_map('intval', explode(',', $value)));

        return empty($ids) ? null : array_values($ids);
    }
}
