<?php

namespace App\Services;

use App\Models\TgMessage;
use danog\MadelineProto\API;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\Database\Mysql;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class TelegramFetcher
{
    private ?API $madelineProto = null;

    public function __construct(private readonly MessageSaver $saver)
    {
    }

    /**
     * Ленивая инициализация MadelineProto — создаём только когда реально нужен.
     */
    private function getMadelineProto(): API
    {
        if ($this->madelineProto === null) {
            $this->initMadelineProto();
        }

        return $this->madelineProto;
    }

    /**
     * Инициализация MadelineProto с MySQL бэкендом для хранения сессии.
     */
    private function initMadelineProto(): void
    {
        $dbConfig = config('parser.madeline_db');

        $dbSettings = (new Mysql())
            ->setUri("tcp://{$dbConfig['host']}:{$dbConfig['port']}")
            ->setDatabase($dbConfig['database'])
            ->setUsername($dbConfig['username'])
            ->setPassword($dbConfig['password']);

        $settings = new Settings();
        $settings->setDb($dbSettings);
        $settings->getAppInfo()
            ->setApiId((int) config('parser.telegram.api_id'))
            ->setApiHash(config('parser.telegram.api_hash'));

        // Кладём лог MadelineProto в storage/logs вместо корня проекта
        $settings->getLogger()
            ->setType(Logger::LOGGER_FILE)
            ->setExtra(storage_path('logs/madelineproto.log'))
            ->setLevel(Logger::LEVEL_WARNING);

        $sessionPath = base_path(config('parser.telegram.session_path'));
        $sessionDir  = dirname($sessionPath);

        if (!is_dir($sessionDir)) {
            mkdir($sessionDir, 0755, true);
        }

        $this->madelineProto = new API($sessionPath, $settings);
        // Восстанавливаем обработчик ошибок Laravel после MadelineProto
        restore_error_handler();
    }

    /**
     * Остановить MadelineProto event loop и освободить ресурсы.
     */
    public function disconnect(): void
    {
        if ($this->madelineProto !== null) {
            try {
                $this->madelineProto->stop();
            } catch (\Throwable $e) {
                // MadelineProto stop без event handler — ожидаемо, игнорируем
                // Log::warning('MadelineProto stop error: ' . $e->getMessage());
            }
            $this->madelineProto = null;
        }
    }

    /**
     * Войти в аккаунт (нужно один раз, интерактивно).
     * После первого входа сессия сохраняется в файл + MySQL.
     */
    public function login(): void
    {
        $this->getMadelineProto()->start();
    }

    /**
     * Главный метод: определяет период загрузки и загружает сообщения.
     */
    public function fetchMessages(): void
    {
        $days      = (int) config('parser.fetch.days', 30);
        $fetchFrom = $this->determineFetchFrom($days);

        Log::info("Fetching messages from {$fetchFrom->toDateTimeString()}");

        $this->getMadelineProto()->start();
        $this->fetchMessagesInRange($fetchFrom, now());
    }

    /**
     * Загрузить сообщения за указанный период.
     * Вызывается из Artisan-команды с опциями --from/--to/--days.
     */
    public function fetchMessagesBetween(Carbon $from, Carbon $to): void
    {
        Log::info("Fetching messages from {$from->toDateTimeString()} to {$to->toDateTimeString()}");

        $this->getMadelineProto()->start();
        $this->fetchMessagesInRange($from, $to);
    }

    /**
     * Определяем дату начала загрузки:
     * - Если сообщений нет — берём последние N дней
     * - Если есть — берём с даты последнего сообщения
     */
    private function determineFetchFrom(int $days): Carbon
    {
        $chatId = $this->getNumericChatId();

        $lastMessage = TgMessage::where('tg_chat_id', $chatId)
            ->orderByDesc('sent_at')
            ->first();

        if ($lastMessage === null) {
            // Сообщений нет — загружаем за последние N дней
            Log::info("No messages in DB, fetching last {$days} days");
            return now()->subDays($days);
        }

        Log::info("Last message in DB: {$lastMessage->sent_at}, fetching from there");
        return $lastMessage->sent_at;
    }

    /**
     * Загружаем сообщения из чата начиная с указанной даты.
     * Используем messages.getHistory с пагинацией.
     */
    private function fetchMessagesInRange(Carbon $from, Carbon $to): void
    {
        $batchSize  = (int) config('parser.fetch.batch_size', 100);
        $offsetId   = 0;
        $totalSaved = 0;
        $mp         = $this->getMadelineProto();
        $chatId     = $this->getNumericChatId();
        $chatName   = $this->getChatName();

        do {
            $result = $mp->messages->getHistory([
                'peer'        => $chatId,
                'offset_id'   => $offsetId,
                'offset_date' => 0,
                'add_offset'  => 0,
                'limit'       => $batchSize,
                'max_id'      => 0,
                'min_id'      => 0,
                'hash'        => 0,
            ]);

            if (empty($result['messages'])) {
                break;
            }

            $messages   = $result['messages'];
            $batchSaved = 0;

            foreach ($messages as $msg) {
                if (($msg['_'] ?? '') !== 'message') {
                    continue;
                }

                $msgDate = Carbon::createFromTimestamp($msg['date']);

                // Пропускаем сообщения новее $to (getHistory идёт от новых к старым)
                if ($msgDate->gt($to)) {
                    continue;
                }

                // Стоп: дошли до даты раньше $from
                if ($msgDate->lt($from)) {
                    Log::info("Reached start date {$from}, stopping fetch");
                    break 2;
                }

                // Извлекаем текст
                $rawText = $this->extractText($msg);

                // Извлекаем автора
                $fromId = $this->extractUserId($msg);

                // Формируем ссылку на сообщение
                $tgLink = $chatName
                    ? "https://t.me/{$chatName}/{$msg['id']}"
                    : null;

                // Определяем реальный chat_id (для супергрупп он отрицательный с префиксом -100)
                $realChatId = $this->extractChatId($result['chats'] ?? [], $chatId);

                $msgData = [
                    'tg_message_id' => $msg['id'],
                    'tg_chat_id'    => $realChatId,
                    'tg_user_id'    => $fromId,
                    'raw_text'      => $rawText,
                    'tg_link'       => $tgLink,
                    'sent_at'       => $msgDate,
                    'display_name'  => $this->extractUserDisplayName($msg),
                    'username'      => $this->extractUsername($msg, $result['users'] ?? []),
                ];

                $this->saver->saveRawMessage($msgData);
                $batchSaved++;
            }

            $totalSaved += $batchSaved;
            Log::info("Batch saved: {$batchSaved} messages (total: {$totalSaved})");

            // Готовим следующую итерацию
            $lastMsg  = end($messages);
            $offsetId = $lastMsg['id'] ?? 0;

            // Задержка чтобы не флудить API
            sleep(1);

        } while (count($messages) === $batchSize);

        Log::info("Fetch complete. Total saved: {$totalSaved} messages");

        // Парсим все сохранённые но не распарсенные сообщения
        $this->parseUnparsed($from);
    }

    /**
     * Парсим все сообщения с is_parsed = false.
     */
    private function parseUnparsed(Carbon $since): void
    {
        $chatId = $this->getNumericChatId();

        TgMessage::where('is_parsed', false)
            ->where('tg_chat_id', $chatId)
            ->where('sent_at', '>=', $since)
            ->chunkById(100, function ($messages) {
                foreach ($messages as $message) {
                    $this->saver->parseAndSave($message);
                }
            });
    }

    /**
     * Извлекаем текст из сообщения (может быть строкой или массивом entities).
     */
    private function extractText(array $msg): string
    {
        $message = $msg['message'] ?? '';

        if (is_string($message)) {
            return $message;
        }

        return '';
    }

    /**
     * Извлекаем ID пользователя из сообщения.
     */
    private function extractUserId(array $msg): ?int
    {
        $fromId = $msg['from_id'] ?? null;

        if (is_array($fromId) && isset($fromId['user_id'])) {
            return (int) $fromId['user_id'];
        }

        if (is_int($fromId)) {
            return $fromId;
        }

        return null;
    }

    /**
     * Получить отображаемое имя из сообщения.
     */
    private function extractUserDisplayName(array $msg): ?string
    {
        return $msg['post_author'] ?? null;
    }

    /**
     * Получить username пользователя из списка users в ответе.
     */
    private function extractUsername(array $msg, array $users): ?string
    {
        $userId = $this->extractUserId($msg);
        if ($userId === null) {
            return null;
        }

        foreach ($users as $user) {
            if (($user['id'] ?? null) === $userId) {
                return $user['username'] ?? null;
            }
        }

        return null;
    }

    /**
     * Получить username чата для формирования ссылок.
     */
    private function getChatUsername(string|int $chatId): ?string
    {
        try {
            $info = $this->getMadelineProto()->getInfo($chatId);
            return $info['username'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Извлечь реальный числовой ID чата.
     */
    private function extractChatId(array $chats, string|int $original): int
    {
        foreach ($chats as $chat) {
            if (isset($chat['id'])) {
                return (int) $chat['id']; // уже содержит -100 префикс
            }
        }

        return (int) $original;
    }

    private function getNumericChatId(): int
    {
        return cache()->rememberForever('trade_chat_numeric_id', function () {
            $chatId = config('parser.telegram.trade_chat_id');
            $info   = $this->getMadelineProto()->getInfo($chatId);
            return (int) ('-100' . $info['Chat']['id']);
        });
    }

    private function getChatName(): string
    {
        return (string) cache()->rememberForever('trade_chat_name', function () {
            $chatId = config('parser.telegram.trade_chat_id');
            if (!is_numeric($chatId)) {
                return ltrim($chatId, '@'); // 'epsilion_trade'
            }
            $info = $this->getMadelineProto()->getInfo($chatId);
            return $info['username'] ?? '';
        });
    }
}
