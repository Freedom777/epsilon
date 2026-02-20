<?php

namespace App\Console\Commands;

use App\Models\Mob;
use Illuminate\Console\Command;
use danog\MadelineProto\API;

class FetchMobs extends Command
{
    protected $signature = 'mobs:fetch
                            {--from=1      : ID с которого начинать}
                            {--to=100      : ID по который брать включительно}
                            {--chat=       : Username или числовой ID чата}
                            {--session=    : Путь к файлу сессии}
                            {--delay-min=1 : Минимальная задержка в секундах}
                            {--delay-max=2 : Максимальная задержка в секундах}
                            {--skip-done   : Пропускать уже успешно обработанные}';

    protected $description = 'Последовательно вызывает /getmob N в Telegram-чате и сохраняет ответы в БД';

    private const RESPONSE_TIMEOUT = 15;

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
            $this->error('Укажите --chat или пропишите epsilon_chat_id в config/parser.php');
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
                $existing = Mob::find($n);
                if ($existing && $existing->status === 'ok') {
                    $bar->advance();
                    continue;
                }
            }

            Mob::updateOrCreate(
                ['id' => $n],
                ['status' => 'process', 'raw_response' => null]
            );

            try {
                $response = $this->sendCommandAndGetResponse($madelineProto, $chatId, "/getmob {$n}");

                if ($response === null) {
                    Mob::where('id', $n)->update(['status' => 'error']);
                    $this->newLine();
                    $this->warn("ID {$n}: нет ответа за " . self::RESPONSE_TIMEOUT . " сек");
                } elseif (trim($response) === '' || $response === '❗️ Монстр не найден') {
                    Mob::where('id', $n)->update(['status' => 'empty']);
                } else {
                    $parsed = $this->parseResponse($response);

                    Mob::where('id', $n)->update([
                        'raw_response' => $response,
                        'status'       => 'ok',
                        ...$parsed,
                    ]);
                }
            } catch (\Throwable $e) {
                Mob::where('id', $n)->update(['status' => 'error']);
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
            'title'    => null,
            'level'    => null,
            'city'     => null,
            'location' => null,
            'exp'      => null,
            'gold'     => null,
            'drop'     => null,
            'extra'    => null,
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

        $dropLines  = [];
        $extraLines = [];
        $parsingDrop = false;

        foreach ($lines as $line) {
            $line = trim($line);

            // Блок дропа
            if ($line === 'Дроп ресурсов:') {
                $parsingDrop = true;
                continue;
            }

            if ($parsingDrop) {
                // Новый известный блок заканчивает дроп
                if ($this->isKnownBlockHeader($line)) {
                    $parsingDrop = false;
                } else {
                    $dropLines[] = $line;
                    continue;
                }
            }

            if (str_starts_with($line, '🔸')) {
                $data['level'] = (int) trim(substr($line, strpos($line, ':') + 1));
            } elseif (str_starts_with($line, '🗺')) {
                $this->parseZone($line, $data);
            } elseif (str_starts_with($line, '✨')) {
                $data['exp'] = (int) trim(substr($line, strpos($line, ':') + 1));
            } elseif (str_starts_with($line, '💰')) {
                $data['gold'] = (int) trim(substr($line, strpos($line, ':') + 1));
            } elseif ($line === 'Награда за убийство:') {
                // служебная строка, пропускаем
            } else {
                $extraLines[] = $line;
            }
        }

        if (!empty($dropLines)) {
            $data['drop'] = $dropLines;
        }

        if (!empty($extraLines)) {
            $data['extra'] = implode("\n", $extraLines);
        }

        return $data;
    }

    /**
     * Разбирает строку зоны вида:
     * 🗺 Зона охоты: 🏞 Устье реки (🏛 Аквелия)
     */
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
        ], true);
    }
}
