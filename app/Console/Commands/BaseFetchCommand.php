<?php

namespace App\Console\Commands;

use App\Enums\MainStatusEnum;
use App\Services\TelegramFetcher;
use danog\MadelineProto\API;
use Illuminate\Console\Command;

abstract class BaseFetchCommand extends Command
{
    private const RESPONSE_TIMEOUT = 15;

    public function __construct(protected readonly TelegramFetcher $fetcher)
    {
        parent::__construct();
    }

    // =========================================================================
    // Абстрактные методы — реализуются в наследниках
    // =========================================================================

    /** Класс модели: Asset::class, Item::class, Mob::class */
    abstract protected function modelClass(): string;

    /** Команда бота: '/getasset', '/getequip', '/getmob' */
    abstract protected function botCommand(): string;

    /** Текст "не найден": '❗️ Ресурс не найден' */
    abstract protected function notFoundText(): string;

    /** Парсинг ответа бота → массив полей для update */
    abstract protected function parseResponse(string $text): array;

    /** Дополнительные поля для обнуления при status=PROCESS */
    protected function processDefaults(): array
    {
        return ['raw_response' => null];
    }

    // =========================================================================
    // Template Method — общий цикл fetch
    // =========================================================================

    protected function handleFetch(): int
    {
        $opts = $this->resolveOptions();

        if (!$this->validateOptions($opts)) {
            return self::FAILURE;
        }

        $model = $this->modelClass();
        $total = $opts['to'] - $opts['from'] + 1;

        $this->info("Запуск: ID {$opts['from']}..{$opts['to']}, чат: {$opts['chatName']}");

        $mp  = $this->getMadelineProto();
        $bar = $this->createProgressBar($total);

        try {
            for ($n = $opts['from']; $n <= $opts['to']; $n++) {
                $bar->setMessage((string) $n);

                if ($opts['skipDone']) {
                    $existing = $model::find($n);
                    if ($existing && $existing->status === MainStatusEnum::OK) {
                        $bar->advance();
                        continue;
                    }
                }

                $model::updateOrCreate(
                    ['id' => $n],
                    ['status' => MainStatusEnum::PROCESS, ...$this->processDefaults()]
                );

                try {
                    $response = $this->sendCommandAndGetResponse(
                        $mp,
                        $opts['chatName'],
                        $this->botCommand() . " {$n}"
                    );

                    if ($response === null) {
                        $model::where('id', $n)->update(['status' => MainStatusEnum::ERROR]);
                        $this->newLine();
                        $this->warn("ID {$n}: нет ответа за " . self::RESPONSE_TIMEOUT . " сек");
                    } elseif (trim($response) === '' || $response === $this->notFoundText()) {
                        $model::where('id', $n)->update(['status' => MainStatusEnum::EMPTY]);
                    } else {
                        $parsed = $this->parseResponse($response);

                        $model::where('id', $n)->update([
                            'raw_response' => $response,
                            'status'       => MainStatusEnum::OK,
                            ...$parsed,
                        ]);
                    }
                } catch (\Throwable $e) {
                    $model::where('id', $n)->update(['status' => MainStatusEnum::ERROR]);
                    $this->newLine();
                    $this->error("ID {$n}: {$e->getMessage()}");
                }

                $bar->advance();

                if ($n < $opts['to']) {
                    $this->randomDelay($opts['delayMin'], $opts['delayMax']);
                }
            }
        } finally {
            $this->fetcher->disconnect();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Готово!');

        return self::SUCCESS;
    }

    // =========================================================================
    // Общие хелперы
    // =========================================================================

    protected function resolveOptions(): array
    {
        return [
            'from'     => (int) $this->option('from'),
            'to'       => (int) $this->option('to'),
            'chatName' => $this->option('chat') ?: config('parser.telegram.epsilon_bot_chat_name'),
            'delayMin' => (int) $this->option('delay-min'),
            'delayMax' => (int) $this->option('delay-max'),
            'skipDone' => (bool) $this->option('skip-done'),
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

    protected function getMadelineProto(): API
    {
        return $this->fetcher->getApi();
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
