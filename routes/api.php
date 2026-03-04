<?php

use App\Http\Controllers\MarketController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Доступные эндпоинты:
|
| GET /api/market
|   Параметры:
|     format=json|html      Формат ответа (по умолчанию: json)
|     currency=gold|cookie  Фильтр по валюте (по умолчанию: все)
|     product_id=1,2,3      Фильтр по ID товаров через запятую
|     days=30               За сколько дней (по умолчанию: 30)
|
| Примеры:
|   /api/market
|   /api/market?currency=gold&format=html
|   /api/market?product_id=5,12,33&currency=cookie
|   /api/market?format=html&days=7
|
*/

Route::get('/market', [MarketController::class, 'index']);

Route::withoutMiddleware(ValidateCsrfToken::class)
    ->post('/market/ping', function () {
        Log::channel('market')->info('view', [
            'ip'    => request()->ip(),
            'agent' => request()->userAgent(),
        ]);
        return response()->noContent();
    });

Route::withoutMiddleware(ValidateCsrfToken::class)
    ->post('/mobs/ping', function () {
        Log::channel('mobs')->info('view', [
            'ip'    => request()->ip(),
            'agent' => request()->userAgent(),
        ]);
        return response()->noContent();
    });


Route::get('/mobs/search', function () {
    $q = request()->string('q')->trim()->lower()->toString();

    if (mb_strlen($q) < 4) {
        return response()->json([]);
    }

    $results = \App\Models\MobDropIndex::whereRaw('LOWER(normalized) LIKE ?', ["%{$q}%"])
        ->selectRaw('drop_text, normalized, COUNT(DISTINCT mob_id) as mob_count')
        ->groupBy('drop_text', 'normalized')
        ->orderByDesc('mob_count')
        ->limit(20)
        ->get();

    return response()->json($results);
});

// Выдача N лучших предложений на странице рынка
Route::get('market/offers', [MarketController::class, 'offers']);
