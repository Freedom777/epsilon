<?php

namespace App\Console\Commands;

use App\Models\TgMessage;
use App\Services\TelegramFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class FetchTelegramMessages extends Command
{
    protected $signature = 'telegram:fetch
                            {--login        : Выполнить первичную авторизацию}
                            {--days=        : Загрузить сообщения за последние N дней (переопределяет PARSER_FETCH_DAYS из .env)}
                            {--from=        : Загрузить с даты (формат: Y-m-d или Y-m-d H:i:s)}
                            {--to=          : Загрузить по дату включительно (формат: Y-m-d, по умолчанию: сейчас)}';

    protected $description = 'Загружает сообщения из Telegram-чата';

    public function handle(TelegramFetcher $fetcher): int
    {
        // --- Авторизация ---
        if ($this->option('login')) {
            $this->info('Запуск авторизации в Telegram...');
            try {
                $fetcher->login();
                $this->info('Авторизация успешна!');
            } finally {
                $fetcher->disconnect();
            }
            return self::SUCCESS;
        }

        // --- Определяем период ---
        [$from, $to] = $this->resolvePeriod();

        if (!$from) {
            return self::FAILURE;
        }

        $this->info("Загрузка сообщений с {$from->toDateTimeString()} по {$to->toDateTimeString()}");

        try {
            $fetcher->fetchMessagesBetween($from, $to);
            $this->info('Загрузка завершена.');
        } catch (\Throwable $e) {
            $this->error('Ошибка: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return self::FAILURE;
        } finally {
            $fetcher->disconnect();
        }

        return self::SUCCESS;
    }

    /**
     * Определяем период из опций.
     * Приоритет: --from/--to > --days > автоматический (из БД или .env)
     */
    private function resolvePeriod(): array
    {
        $to = now();

        if ($toOption = $this->option('to')) {
            try {
                $to = Carbon::parse($toOption)->endOfDay();
            } catch (\Exception) {
                $this->error("Неверный формат --to: {$toOption}. Используйте Y-m-d");
                return [null, null];
            }
        }

        if ($fromOption = $this->option('from')) {
            try {
                $from = Carbon::parse($fromOption)->startOfDay();
                return [$from, $to];
            } catch (\Exception) {
                $this->error("Неверный формат --from: {$fromOption}. Используйте Y-m-d");
                return [null, null];
            }
        }

        if ($daysOption = $this->option('days')) {
            $days = (int) $daysOption;
            if ($days <= 0) {
                $this->error('--days должен быть положительным числом.');
                return [null, null];
            }
            return [now()->subDays($days)->startOfDay(), $to];
        }

        // Автоматически: с последнего сообщения в БД или за FETCH_DAYS дней
        $lastMessage = TgMessage::orderByDesc('sent_at')->first();

        if ($lastMessage) {
            $this->info("Последнее сообщение в БД: {$lastMessage->sent_at}. Загружаем с этой даты.");
            return [$lastMessage->sent_at, $to];
        }

        $days = (int) config('parser.fetch.days', 3);
        $this->info("Сообщений в БД нет. Загружаем за последние {$days} дней.");
        return [now()->subDays($days)->startOfDay(), $to];
    }
}
