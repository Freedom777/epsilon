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
