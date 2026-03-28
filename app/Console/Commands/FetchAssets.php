<?php

namespace App\Console\Commands;

use App\Models\Asset;

class FetchAssets extends BaseFetchCommand
{
    protected $signature = 'assets:fetch
                            {--from=1      : ID с которого начинать}
                            {--to=100      : ID по который брать включительно}
                            {--chat=       : Username или числовой ID чата}
                            {--delay-min=1 : Минимальная задержка в секундах}
                            {--delay-max=2 : Максимальная задержка в секундах}
                            {--skip-done   : Пропускать уже успешно обработанные (status=ok)}';

    protected $description = 'Последовательно вызывает /getasset N в Telegram-чате и сохраняет ответы в БД';

    protected function modelClass(): string { return Asset::class; }
    protected function botCommand(): string { return '/getasset'; }
    protected function notFoundText(): string { return '❗️ Ресурс не найден'; }

    protected function processDefaults(): array
    {
        return ['raw_response' => null, 'title' => null, 'description' => null];
    }

    public function handle(): int
    {
        return $this->handleFetch();
    }

    protected function parseResponse(string $text): array
    {
        $lines = explode("\n", trim($text), 2);
        $title       = trim($lines[0] ?? '');
        $description = trim($lines[1] ?? '');

        return [
            'title'       => $title ?: null,
            'description' => $description ?: null,
        ];
    }
}
