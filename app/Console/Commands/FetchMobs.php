<?php

namespace App\Console\Commands;

use App\Models\Mob;

class FetchMobs extends BaseFetchCommand
{
    protected $signature = 'mobs:fetch
                            {--from=1      : ID с которого начинать}
                            {--to=100      : ID по который брать включительно}
                            {--chat=       : Username или числовой ID чата}
                            {--delay-min=1 : Минимальная задержка в секундах}
                            {--delay-max=2 : Максимальная задержка в секундах}
                            {--skip-done   : Пропускать уже успешно обработанные}';

    protected $description = 'Последовательно вызывает /getmob N в Telegram-чате и сохраняет ответы в БД';

    protected function modelClass(): string { return Mob::class; }
    protected function botCommand(): string { return '/getmob'; }
    protected function notFoundText(): string { return '❗️ Монстр не найден'; }

    public function handle(): int
    {
        return $this->handleFetch();
    }

    protected function parseResponse(string $text): array
    {
        $data = [
            'title'      => null,
            'level'      => null,
            'city'       => null,
            'location'   => null,
            'exp'        => null,
            'gold'       => null,
            'drop_asset' => null,
            'drop_item'  => null,
            'extra'      => null,
        ];

        $lines = array_values(array_filter(
            explode("\n", trim($text)),
            fn(string $line) => trim($line) !== ''
        ));

        // Первую строку (📋 Страница монстра) пропускаем
        array_shift($lines);

        if (empty($lines)) {
            return $data;
        }

        // Вторая строка — title
        $data['title'] = trim(array_shift($lines));

        $dropAssetLines = [];
        $dropItemLines  = [];
        $extraLines     = [];

        $parsingAssetDrop = false;
        $parsingItemDrop  = false;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === 'Дроп ресурсов:') {
                $parsingAssetDrop = true;
                $parsingItemDrop  = false;
                continue;
            }

            if ($line === 'Дроп экипировки:') {
                $parsingItemDrop  = true;
                $parsingAssetDrop = false;
                continue;
            }

            if ($this->isKnownBlockHeader($line)) {
                $parsingAssetDrop = false;
                $parsingItemDrop  = false;
                continue;
            }

            if ($parsingAssetDrop) {
                $dropAssetLines[] = $line;
                continue;
            }

            if ($parsingItemDrop) {
                $dropItemLines[] = $line;
                continue;
            }

            if (str_starts_with($line, '🔸')) {
                $data['level'] = (int) trim(substr($line, strpos($line, ':') + 1));
            } elseif (str_starts_with($line, '🗺')) {
                $this->parseZone($line, $data);
            } elseif (str_starts_with($line, '✨')) {
                $data['exp'] = (int) trim(substr($line, strpos($line, ':') + 1));
            } elseif (str_starts_with($line, '💰')) {
                $data['gold'] = (int) trim(substr($line, strpos($line, ':') + 1));
            } elseif ($line !== 'Награда за убийство:') {
                $extraLines[] = $line;
            }
        }

        if (!empty($dropAssetLines)) {
            $data['drop_asset'] = $dropAssetLines;
        }

        if (!empty($dropItemLines)) {
            $data['drop_item'] = $dropItemLines;
        }

        if (!empty($extraLines)) {
            $data['extra'] = implode("\n", $extraLines);
        }

        return $data;
    }

    private function parseZone(string $line, array &$data): void
    {
        $value = trim(substr($line, strpos($line, ':') + 1));

        if (preg_match('/^(.+?)\s*\((.+?)\)$/', $value, $m)) {
            $data['location'] = trim($m[1]);
            $data['city']     = trim($m[2]);
        } else {
            $data['location'] = $value;
        }
    }

    private function isKnownBlockHeader(string $line): bool
    {
        return in_array($line, [
            'Награда за убийство:',
            'Дроп ресурсов:',
            'Дроп экипировки:',
        ], true);
    }
}
