<?php

namespace App\Console\Commands;

use App\Http\Controllers\MarketController;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class GenerateMarketHtml extends Command
{
    protected $signature   = 'market:generate
                                {--currency= : Фильтр по валюте (gold|cookie)}
                                {--days=30   : За сколько дней}';

    protected $description = 'Генерирует статический HTML файл рынка';

    public function handle(MarketController $controller): int
    {
        $this->info('Генерация market.html...');

        $currencies = ['all', 'gold', 'cookie'];

        foreach ($currencies as $currency) {
            $request = Request::create('/api/market', 'GET', array_filter([
                'format'   => 'html',
                'currency' => $currency !== 'all' ? $currency : null,
                'days'     => $this->option('days'),
            ]));

            $response = $controller->index($request);
            $html     = $response->getContent();
            $filename = $currency === 'all' ? 'market.html' : "market-{$currency}.html";
            $path     = public_path($filename);

            file_put_contents($path, $html);
            $this->line("  ✓ {$filename}");
        }

        $this->info('Готово.');

        return self::SUCCESS;
    }
}
