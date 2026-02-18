<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\Product;
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
     *   ?format=json|html     — формат ответа (default: json)
     *   ?currency=gold|cookie — фильтр по валюте (default: все)
     *   ?product_id=1,2,3     — фильтр по ID товаров (default: все)
     *   ?days=30              — за сколько дней (default: 30)
     */
    public function index(Request $request): JsonResponse|Response
    {
        $format     = $request->get('format', 'json');
        $currency   = $request->get('currency');
        $productIds = $request->get('product_id');
        $days       = (int) $request->get('days', config('parser.api.default_days', 30));

        // Парсим product_id если передан через запятую
        $productIdList = null;
        if ($productIds) {
            $productIdList = array_filter(
                array_map('intval', explode(',', $productIds))
            );
        }

        $data = $this->buildMarketData($currency, $productIdList, $days);

        if ($format === 'html') {
            return response($this->renderHtml($data, $currency, $days))
                ->header('Content-Type', 'text/html; charset=utf-8');
        }

        return response()->json([
            'meta' => [
                'days'       => $days,
                'currency'   => $currency ?? 'all',
                'total'      => count($data),
                'generated'  => now()->toIso8601String(),
            ],
            'data' => $data,
        ]);
    }

    /**
     * Строим данные для ответа.
     * Для каждого товара: макс цена покупки + кто/ссылка/дата, мин цена продажи + кто/ссылка/дата.
     */
    private function buildMarketData(?string $currency, ?array $productIds, int $days): array
    {
        $since = now()->subDays($days);

        // Запрос для покупок (максимальная цена)
        $buyQuery = DB::table('listings as l')
            ->join('tg_messages as m', 'l.tg_message_id', '=', 'm.id')
            ->join('products as p', 'l.product_id', '=', 'p.id')
            ->leftJoin('tg_users as u', 'l.tg_user_id', '=', 'u.id')
            ->where('l.type', 'buy')
            ->where('l.status', '!=', 'invalid')
            ->whereNotNull('l.price')
            ->where('l.posted_at', '>=', $since)
            ->select([
                DB::raw('COALESCE(p.parent_id, p.id) as effective_product_id'),
                DB::raw('MAX(l.price) as max_buy_price'),
            ])
            ->groupBy('effective_product_id');

        // Запрос для продаж (минимальная цена)
        $sellQuery = DB::table('listings as l')
            ->join('tg_messages as m', 'l.tg_message_id', '=', 'm.id')
            ->join('products as p', 'l.product_id', '=', 'p.id')
            ->leftJoin('tg_users as u', 'l.tg_user_id', '=', 'u.id')
            ->where('l.type', 'sell')
            ->where('l.status', '!=', 'invalid')
            ->whereNotNull('l.price')
            ->where('l.posted_at', '>=', $since)
            ->select([
                DB::raw('COALESCE(p.parent_id, p.id) as effective_product_id'),
                DB::raw('MIN(l.price) as min_sell_price'),
            ])
            ->groupBy('effective_product_id');

        if ($currency) {
            $buyQuery->where('l.currency', $currency);
            $sellQuery->where('l.currency', $currency);
        }

        if ($productIds) {
            $buyQuery->whereIn(DB::raw('COALESCE(p.parent_id, p.id)'), $productIds);
            $sellQuery->whereIn(DB::raw('COALESCE(p.parent_id, p.id)'), $productIds);
        }

        $buyPrices  = $buyQuery->pluck('max_buy_price', 'effective_product_id');
        $sellPrices = $sellQuery->pluck('min_sell_price', 'effective_product_id');

        // Объединяем ID товаров из обоих источников
        $allProductIds = $buyPrices->keys()
            ->merge($sellPrices->keys())
            ->unique()
            ->values();

        if ($productIds) {
            $allProductIds = $allProductIds->filter(fn($id) => in_array($id, $productIds))->values();
        }

        $result = [];

        foreach ($allProductIds as $productId) {
            $product = Product::find($productId);
            if (!$product) {
                continue;
            }

            $row = [
                'product_id'   => $product->id,
                'product_name' => $product->name,
                'product_icon' => $product->icon,
                'full_name'    => $product->full_name,
                'currency'     => $currency ?? 'gold',
                'buy'          => null,
                'sell'         => null,
            ];

            // Лучшая покупка (максимальная цена)
            if (isset($buyPrices[$productId])) {
                $bestBuy = $this->getBestListing(
                    $productId, 'buy', $currency, $buyPrices[$productId], $since, 'max'
                );
                $row['buy'] = $bestBuy;
            }

            // Лучшая продажа (минимальная цена)
            if (isset($sellPrices[$productId])) {
                $bestSell = $this->getBestListing(
                    $productId, 'sell', $currency, $sellPrices[$productId], $since, 'min'
                );
                $row['sell'] = $bestSell;
            }

            $result[] = $row;
        }

        // Сортируем по имени товара
        usort($result, fn($a, $b) => strcmp($a['product_name'], $b['product_name']));

        return $result;
    }

    /**
     * Получаем детали конкретного листинга (лучшая цена + автор + ссылка + дата).
     */
    private function getBestListing(
        int $productId,
        string $type,
        ?string $currency,
        int $price,
        \Carbon\Carbon $since,
        string $direction // 'max' | 'min'
    ): ?array {
        $query = Listing::with(['user', 'message'])
            ->whereHas('product', function ($q) use ($productId) {
                $q->where('id', $productId)
                  ->orWhere('parent_id', $productId);
            })
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

        $user      = $listing->user;
        $message   = $listing->message;
        $userLink  = $user?->tg_profile_link;
        $userDisplay = $user?->display ?? 'Неизвестен';

        return [
            'price'       => $price,
            'currency'    => $listing->currency,
            'posted_at'   => $listing->posted_at?->toIso8601String(),
            'tg_link'     => $message?->tg_link,
            'user_display' => $userDisplay,
            'user_tg_link' => $userLink,
            'status'      => $listing->status,
        ];
    }

    /**
     * Рендерим HTML-таблицу.
     */
    private function renderHtml(array $data, ?string $currency, int $days): string
    {
        $currencyLabel = match ($currency) {
            'gold'   => '💰 Золото',
            'cookie' => '🍪 Печеньки',
            default  => 'Все валюты',
        };

        $rows = '';
        foreach ($data as $item) {
            $buyCell  = $this->formatPriceCell($item['buy']);
            $sellCell = $this->formatPriceCell($item['sell']);

            $rows .= "<tr>
                <td>{$item['full_name']}</td>
                {$buyCell}
                {$sellCell}
            </tr>";
        }

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
    <div class="meta">Данные за последние {$days} дней &nbsp;|&nbsp; {$currencyLabel} &nbsp;|&nbsp; Обновлено: {$this->now()}</div>
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

    /**
     * Форматируем ячейку с ценой для HTML.
     */
    private function formatPriceCell(?array $data): string
    {
        if (!$data) {
            return '<td colspan="1" class="no-data">—</td>';
        }

        $currencySymbol = $data['currency'] === 'cookie' ? '🍪' : '💰';
        $price  = number_format($data['price'], 0, '.', ' ');
        $status = $data['status'] === 'suspicious' ? ' class="suspicious" title="Подозрительная цена"' : '';

        $userHtml = $data['user_tg_link']
            ? "<a href=\"{$data['user_tg_link']}\" target=\"_blank\">{$data['user_display']}</a>"
            : htmlspecialchars($data['user_display'] ?? '');

        $dateFormatted = $data['posted_at']
            ? date('d.m.Y H:i', strtotime($data['posted_at']))
            : '';

        $dateHtml = $data['tg_link']
            ? "<a href=\"{$data['tg_link']}\" target=\"_blank\">{$dateFormatted}</a>"
            : $dateFormatted;

        return "<td>
            <span class=\"price\"{$status}>{$price} {$currencySymbol}</span><br>
            <span class=\"user\">{$userHtml}</span><br>
            <span class=\"date\">{$dateHtml}</span>
        </td>";
    }

    private function now(): string
    {
        return now()->format('d.m.Y H:i');
    }
}
