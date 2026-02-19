<?php

namespace App\Console\Commands;

use App\Models\Asset;
use Illuminate\Console\Command;
use danog\MadelineProto\API;

class FetchAssets extends Command
{
    protected $signature = 'assets:fetch
                            {--from=1      : ID с которого начинать}
                            {--to=100      : ID по который брать включительно}
                            {--chat=       : Username или числовой ID чата}
                            {--session=    : Путь к файлу сессии (по умолчанию из .env)}
                            {--delay-min=1 : Минимальная задержка в секундах}
                            {--delay-max=2 : Максимальная задержка в секундах}
                            {--skip-done   : Пропускать уже успешно обработанные (status=ok)}';

    protected $description = 'Последовательно вызывает /getasset N в Telegram-чате и сохраняет ответы в БД';

    // Сколько секунд ждём ответ от бота
    private const RESPONSE_TIMEOUT = 15;

    public function handle(): int
    {
        $from       = (int) $this->option('from');
        $to         = (int) $this->option('to');
        $chatId     = $this->option('chat') ?: config('parser.telegram.epsilon_chat_id');
        $sessionPath = $this->option('session') ?: config('parser.telegram.session_path');
        $delayMin   = (int) $this->option('delay-min');
        $delayMax   = (int) $this->option('delay-max');
        $skipDone   = (bool) $this->option('skip-done');

        if (!$chatId) {
            $this->error('Укажите --chat или пропишите TELEGRAM_EPSILON_CHAT_ID в .env');
            return self::FAILURE;
        }

        if ($from > $to) {
            $this->error('--from не может быть больше --to');
            return self::FAILURE;
        }

        $this->info("Запуск: ID {$from}..{$to}, чат: {$chatId}");

        // Инициализация MadelineProto
        $madelineProto = new API($sessionPath);
        $madelineProto->start(); // использует существующую сессию

        $total   = $to - $from + 1;
        $bar     = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | ID: %message%');
        $bar->start();

        for ($n = $from; $n <= $to; $n++) {
            $bar->setMessage((string) $n);

            // Пропуск уже готовых
            if ($skipDone) {
                $existing = Asset::find($n);
                if ($existing && $existing->status === 'ok') {
                    $bar->advance();
                    continue;
                }
            }

            // Создаём/обновляем запись со статусом process
            Asset::updateOrCreate(
                ['id' => $n],
                ['status' => 'process', 'raw_response' => null, 'title' => null, 'description' => null]
            );

            try {
                $response = $this->sendCommandAndGetResponse($madelineProto, $chatId, "/getasset {$n}");

                if ($response === null) {
                    // Бот не ответил в отведённое время
                    Asset::where('id', $n)->update(['status' => 'error']);
                    $this->newLine();
                    $this->warn("ID {$n}: нет ответа за " . self::RESPONSE_TIMEOUT . " сек");
                } elseif (trim($response) === '' || $response === '❗️ Ресурс не найден') {
                    Asset::where('id', $n)->update(['status' => 'empty']);
                } else {
                    [$title, $description] = $this->parseResponse($response);

                    Asset::where('id', $n)->update([
                        'raw_response' => $response,
                        'title'        => $title,
                        'description'  => $description,
                        'status'       => 'ok',
                    ]);
                }
            } catch (\Throwable $e) {
                Asset::where('id', $n)->update(['status' => 'error']);
                $this->newLine();
                $this->error("ID {$n}: {$e->getMessage()}");
            }

            $bar->advance();

            // Случайная задержка (кроме последнего)
            if ($n < $to) {
                $delay = rand($delayMin * 1000, $delayMax * 1000); // в миллисекундах
                usleep($delay * 1000);
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Готово!');

        return self::SUCCESS;
    }

    /**
     * Отправляет команду в чат и ждёт ответа бота.
     * Возвращает текст первого нового сообщения или null при таймауте.
     */
    private function sendCommandAndGetResponse(API $madelineProto, string|int $chatId, string $command): ?string
    {
        // Запоминаем ID последнего сообщения ДО отправки
        $historyBefore = $madelineProto->messages->getHistory(
            peer: $chatId,
            limit: 1,
        );

        $lastIdBefore = 0;
        if (!empty($historyBefore['messages'])) {
            $lastIdBefore = $historyBefore['messages'][0]['id'];
        }

        // Отправляем команду
        $madelineProto->messages->sendMessage(
            peer: $chatId,
            message: $command,
        );

        // Polling: ждём нового сообщения
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

            // Берём самое старое новое сообщение (не наше)
            $messages = array_reverse($history['messages']); // от старых к новым
            foreach ($messages as $msg) {
                if ($msg['id'] <= $lastIdBefore) {
                    continue;
                }
                // Пропускаем наши собственные сообщения
                if (!empty($msg['out'])) {
                    continue;
                }
                return $msg['message'] ?? '';
            }
        }

        return null;
    }

    /**
     * Разбирает ответ на title (первая строка) и description (остальное).
     */
    private function parseResponse(string $text): array
    {
        $lines = explode("\n", trim($text), 2);
        $title = trim($lines[0] ?? '');
        $description = trim($lines[1] ?? '');

        return [$title ?: null, $description ?: null];
    }
}
