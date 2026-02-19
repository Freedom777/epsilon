<?php

namespace App\Console\Commands;

use App\Models\Item;
use Illuminate\Console\Command;
use danog\MadelineProto\API;

class FetchItems extends Command
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
        $from        = (int) $this->option('from');
        $to          = (int) $this->option('to');
        $chatId      = $this->option('chat') ?: config('parser.telegram.epsilon_chat_id');
        $sessionPath = $this->option('session') ?: config('parser.telegram.session_path');
        $delayMin    = (int) $this->option('delay-min');
        $delayMax    = (int) $this->option('delay-max');
        $skipDone    = (bool) $this->option('skip-done');

        if (!$chatId) {
            $this->error('Укажите --chat или пропишите TELEGRAM_EPSILON_CHAT_ID в .env');
            return self::FAILURE;
        }

        if ($from > $to) {
            $this->error('--from не может быть больше --to');
            return self::FAILURE;
        }

        $this->info("Запуск: ID {$from}..{$to}, чат: {$chatId}");

        $madelineProto = new API($sessionPath);
        $madelineProto->start();

        $bar = $this->output->createProgressBar($to - $from + 1);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | ID: %message%');
        $bar->start();

        for ($n = $from; $n <= $to; $n++) {
            $bar->setMessage((string) $n);

            if ($skipDone) {
                $existing = Item::find($n);
                if ($existing && $existing->status === 'ok') {
                    $bar->advance();
                    continue;
                }
            }

            Item::updateOrCreate(
                ['id' => $n],
                ['status' => 'process', 'raw_response' => null]
            );

            try {
                $response = $this->sendCommandAndGetResponse($madelineProto, $chatId, "/getequip {$n}");

                if ($response === null) {
                    Item::where('id', $n)->update(['status' => 'error']);
                    $this->newLine();
                    $this->warn("ID {$n}: нет ответа за " . self::RESPONSE_TIMEOUT . " сек");
                } elseif (trim($response) === '' || $response === '❗️ Экипировка не найдена') {
                    Item::where('id', $n)->update(['status' => 'empty']);
                } else {
                    $parsed = $this->parseResponse($response);

                    Item::where('id', $n)->update([
                        'raw_response' => $response,
                        'status'       => 'ok',
                        ...$parsed,
                    ]);
                }
            } catch (\Throwable $e) {
                Item::where('id', $n)->update(['status' => 'error']);
                $this->newLine();
                $this->error("ID {$n}: {$e->getMessage()}");
            }

            $bar->advance();

            if ($n < $to) {
                usleep(rand($delayMin * 1000, $delayMax * 1000) * 1000);
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Готово!');

        return self::SUCCESS;
    }

    private function sendCommandAndGetResponse(API $madelineProto, string|int $chatId, string $command): ?string
    {
        $historyBefore = $madelineProto->messages->getHistory(
            peer: $chatId,
            limit: 1,
        );

        $lastIdBefore = $historyBefore['messages'][0]['id'] ?? 0;

        $madelineProto->messages->sendMessage(
            peer: $chatId,
            message: $command,
        );

        $deadline = time() + self::RESPONSE_TIMEOUT;

        while (time() < $deadline) {
            sleep(1);

            $history = $madelineProto->messages->getHistory(
                peer: $chatId,
                limit: 5,
                min_id: $lastIdBefore,
            );

            if (empty($history['messages'])) {
                continue;
            }

            foreach (array_reverse($history['messages']) as $msg) {
                if ($msg['id'] <= $lastIdBefore || !empty($msg['out'])) {
                    continue;
                }
                return $msg['message'] ?? '';
            }
        }

        return null;
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
            'personal'       => false,
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
                    $data['personal'] = true;
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
