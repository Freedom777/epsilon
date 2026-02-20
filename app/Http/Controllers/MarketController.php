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
    /**
     * GET /api/market
     *
     * Параметры:
     *   ?format=json|html          — формат ответа (default: json)
     *   ?currency=gold|cookie      — фильтр по валюте (default: все)
     *   ?asset_id=1,2,3            — фильтр по ID расходников
     *   ?item_id=1,2,3             — фильтр по ID экипировки
     *   ?days=30                   — за сколько дней (default: 30)
     */
    public function index(Request $request): JsonResponse|Response
    {
        $format   = $request->string('format', 'json')->value();
        $currency = $request->string('currency')->value() ?: null;
        $days     = $request->integer('days', config('parser.fetch.days', 30));

        $assetIds = $this->parseIdList($request->string('asset_id')->value());
        $itemIds  = $this->parseIdList($request->string('item_id')->value());

        $data = $this->buildMarketData($currency, $assetIds, $itemIds, $days);

        if ($format === 'html') {
            return response($this->renderHtml($data, $currency, $days))
                ->header('Content-Type', 'text/html; charset=utf-8');
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

        // Агрегируем лучшие цены по asset_id и item_id
        $buyPricesAsset  = $this->getAggregatePrices('buy',  'asset_id', $currency, $assetIds, $since);
        $sellPricesAsset = $this->getAggregatePrices('sell', 'asset_id', $currency, $assetIds, $since);
        $buyPricesItem   = $this->getAggregatePrices('buy',  'item_id',  $currency, $itemIds,  $since);
        $sellPricesItem  = $this->getAggregatePrices('sell', 'item_id',  $currency, $itemIds,  $since);

        // Все задействованные asset IDs
        $allAssetIds = $buyPricesAsset->keys()
            ->merge($sellPricesAsset->keys())
            ->unique()->values();

        // Все задействованные item IDs
        $allItemIds = $buyPricesItem->keys()
            ->merge($sellPricesItem->keys())
            ->unique()->values();

        $result = [];

        // Расходники
        foreach ($allAssetIds as $assetId) {
            $asset = Asset::find($assetId);
            if (!$asset) continue;

            $row = [
                'asset_id'     => $asset->id,
                'item_id'      => null,
                'product_name' => $asset->title,
                'product_icon' => null,
                'grade'        => $asset->grade,
                'type'         => $asset->type,
                'currency'     => $currency ?? 'gold',
                'buy'          => null,
                'sell'         => null,
            ];

            if ($buyPricesAsset->has($assetId)) {
                $row['buy'] = $this->getBestListing(
                    'asset_id', $assetId, 'buy', $currency, $buyPricesAsset[$assetId], $since
                );
            }

            if ($sellPricesAsset->has($assetId)) {
                $row['sell'] = $this->getBestListing(
                    'asset_id', $assetId, 'sell', $currency, $sellPricesAsset[$assetId], $since
                );
            }

            $result[] = $row;
        }

        // Экипировка
        foreach ($allItemIds as $itemId) {
            $item = Item::find($itemId);
            if (!$item) continue;

            $row = [
                'asset_id'     => null,
                'item_id'      => $item->id,
                'product_name' => $item->title,
                'product_icon' => null,
                'grade'        => $item->grade,
                'type'         => $item->type,
                'currency'     => $currency ?? 'gold',
                'buy'          => null,
                'sell'         => null,
            ];

            if ($buyPricesItem->has($itemId)) {
                $row['buy'] = $this->getBestListing(
                    'item_id', $itemId, 'buy', $currency, $buyPricesItem[$itemId], $since
                );
            }

            if ($sellPricesItem->has($itemId)) {
                $row['sell'] = $this->getBestListing(
                    'item_id', $itemId, 'sell', $currency, $sellPricesItem[$itemId], $since
                );
            }

            $result[] = $row;
        }

        usort($result, fn($a, $b) => strcmp($a['product_name'], $b['product_name']));

        return $result;
    }

    /**
     * Получаем агрегированные цены (max для buy, min для sell).
     */
    private function getAggregatePrices(
        string  $type,       // 'buy' | 'sell'
        string  $column,     // 'asset_id' | 'item_id'
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
            ->select([
                $column,
                DB::raw("{$aggregate}(price) as best_price"),
            ])
            ->groupBy($column);

        if ($currency) {
            $query->where('currency', $currency);
        }

        if ($ids) {
            $query->whereIn($column, $ids);
        }

        return $query->pluck('best_price', $column);
    }

    /**
     * Получаем детали конкретного листинга (лучшая цена + автор + ссылка + дата).
     */
    private function getBestListing(
        string $column,    // 'asset_id' | 'item_id'
        int    $id,
        string $type,
        ?string $currency,
        int    $price,
        \Carbon\Carbon $since
    ): ?array {
        $query = Listing::with(['tgUser', 'tgMessage'])
            ->where($column, $id)
            ->where('type', $type)
            ->where('price', $price)
            ->where('status', '!=', 'invalid')
            ->where('posted_at', '>=', $since);

        if ($currency) {
            $query->where('currency', $currency);
        }

        $listing = $query->orderByDesc('posted_at')->first();

        if (!$listing) {
            return null;
        }

        $user        = $listing->tgUser;
        $message     = $listing->tgMessage;
        $userDisplay = $user?->display_name ?? 'Неизвестен';
        $userLink    = $user?->tg_link ?? null;

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
    // HTML рендер
    // =========================================================================

    private function renderHtml(array $data, ?string $currency, int $days): string
    {
        $currencyLabel = match ($currency) {
            'gold'   => '💰 Золото',
            'cookie' => '🍪 Печеньки',
            default  => 'Все валюты',
        };

        $rows = '';
        foreach ($data as $item) {
            $gradeLabel = $item['grade'] ? " [{$item['grade']}]" : '';
            $typeLabel  = $item['asset_id'] ? '📦' : '⚔️';
            $fullName   = $typeLabel . ' ' . htmlspecialchars($item['product_name']) . $gradeLabel;

            $buyCell  = $this->formatPriceCell($item['buy']);
            $sellCell = $this->formatPriceCell($item['sell']);

            $rows .= "<tr>
                <td>{$fullName}</td>
                {$buyCell}
                {$sellCell}
            </tr>";
        }

        $now = now()->format('d.m.Y H:i');

        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Рынок Epsilion War</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #1a1a2e; color: #eee; }
        h1 { color: #f0c040; }
        .meta { color: #aaa; margin-bottom: 20px; font-size: 0.9em; }
        table { width: 100%; border-collapse: collapse; background: #16213e; }
        th { background: #0f3460; color: #f0c040; padding: 10px; text-align: left; }
        td { padding: 8px 10px; border-bottom: 1px solid #333; }
        tr:hover { background: #1a2a50; }
        .price { font-weight: bold; color: #f0c040; }
        .user a { color: #7ec8e3; text-decoration: none; }
        .user a:hover { text-decoration: underline; }
        .date a { color: #aaa; font-size: 0.85em; text-decoration: none; }
        .date a:hover { text-decoration: underline; }
        .suspicious { color: #ff9900; }
        .no-data { color: #555; font-style: italic; }
    </style>
</head>
<body>
    <h1>🏪 Рынок Epsilion War</h1>
    <div class="meta">Данные за последние {$days} дней &nbsp;|&nbsp; {$currencyLabel} &nbsp;|&nbsp; Обновлено: {$now}</div>
    <table>
        <thead>
            <tr>
                <th>Товар</th>
                <th>💰 Макс. цена покупки</th>
                <th>💰 Мин. цена продажи</th>
            </tr>
        </thead>
        <tbody>
            {$rows}
        </tbody>
    </table>
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
            : e($data['user_display'] ?? '');

        $dateFormatted = $data['posted_at']
            ? date('d.m.Y H:i', strtotime($data['posted_at']))
            : '';

        $dateHtml = $data['tg_link']
            ? '<a href="' . e($data['tg_link']) . '" target="_blank">' . $dateFormatted . '</a>'
            : $dateFormatted;

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
        if (blank($value)) {
            return null;
        }

        $ids = array_filter(array_map('intval', explode(',', $value)));

        return empty($ids) ? null : array_values($ids);
    }
}
