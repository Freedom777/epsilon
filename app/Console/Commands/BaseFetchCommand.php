<?php

namespace App\Console\Commands;

use danog\MadelineProto\API;
use Illuminate\Console\Command;

abstract class BaseFetchCommand extends Command
{
    private const RESPONSE_TIMEOUT = 15;

    protected function resolveOptions(): array
    {
        return [
            'from'        => (int) $this->option('from'),
            'to'          => (int) $this->option('to'),
            'chatName'    => $this->option('chat') ?: config('parser.telegram.epsilon_bot_chat_name'),
            'sessionPath' => $this->option('session') ?: config('parser.telegram.session_path'),
            'delayMin'    => (int) $this->option('delay-min'),
            'delayMax'    => (int) $this->option('delay-max'),
            'skipDone'    => (bool) $this->option('skip-done'),
        ];
    }

    protected function validateOptions(array $opts): bool
    {
        if (!$opts['chatName']) {
            $this->error('Укажите --chat или пропишите TELEGRAM_EPSILON_CHAT_ID в .env');
            return false;
        }

        if ($opts['from'] > $opts['to']) {
            $this->error('--from не может быть больше --to');
            return false;
        }

        return true;
    }

    protected function initMadelineProto(string $sessionPath): API
    {
        $mp = new API($sessionPath);
        $mp->start();
        return $mp;
    }

    protected function createProgressBar(int $total): \Symfony\Component\Console\Helper\ProgressBar
    {
        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | ID: %message%');
        $bar->start();
        return $bar;
    }

    protected function randomDelay(int $delayMin, int $delayMax): void
    {
        usleep(rand($delayMin * 1000, $delayMax * 1000) * 1000);
    }

    /**
     * Отправляет команду в чат и ждёт ответа бота.
     * Возвращает текст первого нового сообщения или null при таймауте.
     */
    protected function sendCommandAndGetResponse(API $madelineProto, string|int $chatId, string $command): ?string
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
}
