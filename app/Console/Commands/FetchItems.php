<?php

namespace App\Console\Commands;

use App\Enums\MainStatusEnum;
use App\Models\Item;

class FetchItems extends BaseFetchCommand
{
    protected $signature = 'items:fetch
                            {--from=1      : ID с которого начинать}
                            {--to=100      : ID по который брать включительно}
                            {--chat=       : Username или числовой ID чата}
                            {--session=    : Путь к файлу сессии}
                            {--delay-min=1 : Минимальная задержка в секундах}
                            {--delay-max=2 : Максимальная задержка в секундах}
                            {--skip-done   : Пропускать уже успешно обработанные}';

    protected $description = 'Последовательно вызывает /getequip N в Telegram-чате и сохраняет ответы в БД';

    private const RESPONSE_TIMEOUT = 15;

    private const FIELD_MAP = [
        '❇️' => 'type',
        '📏' => 'subtype',
        '💎' => 'rarity',
        '⚙️' => 'durability_max',
        '💰' => 'price',
    ];

    public function handle(): int
    {
        $opts = $this->resolveOptions();

        if (!$this->validateOptions($opts)) {
            return self::FAILURE;
        }

        $this->info("Запуск: ID {$opts['from']}..{$opts['to']}, чат: {$opts['chatName']}");

        $mp  = $this->initMadelineProto($opts['sessionPath']);
        $bar = $this->createProgressBar($opts['to'] - $opts['from'] + 1);

        for ($n = $opts['from']; $n <= $opts['to']; $n++) {
            $bar->setMessage((string) $n);

            if ($opts['skipDone']) {
                $existing = Item::find($n);
                if ($existing && $existing->status === MainStatusEnum::OK) {
                    $bar->advance();
                    continue;
                }
            }

            Item::updateOrCreate(
                ['id' => $n],
                ['status' => MainStatusEnum::PROCESS, 'raw_response' => null]
            );

            try {
                $response = $this->sendCommandAndGetResponse($mp, $opts['chatName'], "/getequip {$n}");

                if ($response === null) {
                    Item::where('id', $n)->update(['status' => MainStatusEnum::ERROR]);
                    $this->newLine();
                    $this->warn("ID {$n}: нет ответа за " . self::RESPONSE_TIMEOUT . " сек");
                } elseif (trim($response) === '' || $response === '❗️ Экипировка не найдена') {
                    Item::where('id', $n)->update(['status' => MainStatusEnum::EMPTY]);
                } else {
                    $parsed = $this->parseResponse($response);

                    Item::where('id', $n)->update([
                        'raw_response' => $response,
                        'status'       => MainStatusEnum::OK,
                        ...$parsed,
                    ]);
                }
            } catch (\Throwable $e) {
                Item::where('id', $n)->update(['status' => MainStatusEnum::ERROR]);
                $this->newLine();
                $this->error("ID {$n}: {$e->getMessage()}");
            }

            $bar->advance();

            if ($n < $opts['to']) {
                $this->randomDelay($opts['delayMin'], $opts['delayMax']);
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Готово!');

        return self::SUCCESS;
    }

    private function parseResponse(string $text): array
    {
        $data = [
            'title'          => null,
            'description'    => null,
            'type'           => null,
            'subtype'        => null,
            'grade'          => null,
            'rarity'         => null,
            'extra'          => null,
            'durability_max' => null,
            'is_personal'       => false,
            'price'          => null,
        ];

        $lines = array_values(array_filter(
            explode("\n", trim($text)),
            fn(string $line) => trim($line) !== ''
        ));

        // Первую строку (📋 Страница экипировки) пропускаем
        array_shift($lines);

        if (empty($lines)) {
            return $data;
        }

        // Вторая строка — title, убираем финальный " :"
        $data['title'] = preg_replace('/\s*:\s*$/', '', trim(array_shift($lines)));

        $descLines  = [];
        $extraLines = [];
        $parsingDesc = true;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($parsingDesc && !$this->isKnownFieldLine($line)) {
                $descLines[] = $line;
                continue;
            }

            $parsingDesc = false;

            $matched = false;
            foreach (self::FIELD_MAP as $emoji => $field) {
                if (str_starts_with($line, $emoji)) {
                    $value = trim(substr($line, strpos($line, ':') + 1));
                    $data[$field] = match($field) {
                        'durability_max', 'price' => (int) $value,
                        default                   => $value,
                    };
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                if (str_starts_with($line, '☢️') && preg_match('/\[(.+?)]/', $line, $m)) {
                    $data['grade'] = $m[1];
                } elseif (str_starts_with($line, '📌')) {
                    $data['is_personal'] = true;
                } else {
                    $extraLines[] = $line;
                }
            }
        }

        if (!empty($descLines)) {
            $data['description'] = implode("\n", $descLines);
        }

        if (!empty($extraLines)) {
            $data['extra'] = implode("\n", $extraLines);
        }

        return $data;
    }

    private function isKnownFieldLine(string $line): bool
    {
        $knownPrefixes = [...array_keys(self::FIELD_MAP), '☢️', '📌', 'Бонусы', 'Требования', '·'];

        foreach ($knownPrefixes as $prefix) {
            if (str_starts_with($line, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
