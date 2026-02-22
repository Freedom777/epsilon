<?php

namespace App\Console\Commands;

use App\Models\TgMessage;
use App\Services\MessageSaver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ParseMessages extends Command
{
    protected $signature = 'telegram:parse
        {--days= : За сколько дней}
        {--from= : Дата начала}';

    protected $description = 'Парсинг загруженных сообщений из tg_messages (без обращения к Telegram)';

    public function handle(MessageSaver $saver): int
    {
        $days  = (int) ($this->option('days') ?: config('parser.fetch.days', 3));
        $since = $this->option('from')
            ? Carbon::parse($this->option('from'))
            : now()->subDays($days);

        $chatId = (int) config('parser.telegram.epsilon_trade_chat_id');

        $query = TgMessage::where('is_parsed', false)
            ->where('tg_chat_id', $chatId)
            ->where('sent_at', '>=', $since);

        $total = $query->count();

        if ($total === 0) {
            $this->info('Нет сообщений для парсинга.');
            return self::SUCCESS;
        }

        $this->info("Парсинг {$total} сообщений...");
        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
        $bar->start();

        $query->chunkById(100, function ($messages) use ($saver, $bar) {
            foreach ($messages as $message) {
                $saver->parseAndSave($message);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info('Готово!');

        return self::SUCCESS;
    }
}
