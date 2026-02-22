<?php

namespace App\Console\Commands;

use App\Enums\MainStatusEnum;
use App\Models\Asset;

class FetchAssets extends BaseFetchCommand
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
        $opts = $this->resolveOptions();

        if (!$this->validateOptions($opts)) {
            return self::FAILURE;
        }

        $this->info("Запуск: ID {$opts['from']}..{$opts['to']}, чат: {$opts['chatName']}");

        $mp  = $this->initMadelineProto($opts['sessionPath']);
        $bar = $this->createProgressBar($opts['to'] - $opts['from'] + 1);

        for ($n = $opts['from']; $n <= $opts['to']; $n++) {
            $bar->setMessage((string) $n);

            // Пропуск уже готовых
            if ($opts['skipDone']) {
                $existing = Asset::find($n);
                if ($existing && $existing->status === MainStatusEnum::OK) {
                    $bar->advance();
                    continue;
                }
            }

            // Создаём/обновляем запись со статусом process
            Asset::updateOrCreate(
                ['id' => $n],
                ['status' => MainStatusEnum::PROCESS, 'raw_response' => null, 'title' => null, 'description' => null]
            );

            try {
                $response = $this->sendCommandAndGetResponse($mp, $opts['chatName'], "/getasset {$n}");

                if ($response === null) {
                    // Бот не ответил в отведённое время
                    Asset::where('id', $n)->update(['status' => MainStatusEnum::ERROR]);
                    $this->newLine();
                    $this->warn("ID {$n}: нет ответа за " . self::RESPONSE_TIMEOUT . " сек");
                } elseif (trim($response) === '' || $response === '❗️ Ресурс не найден') {
                    Asset::where('id', $n)->update(['status' => MainStatusEnum::EMPTY]);
                } else {
                    [$title, $description] = $this->parseResponse($response);

                    Asset::where('id', $n)->update([
                        'raw_response' => $response,
                        'title'        => $title,
                        'description'  => $description,
                        'status'       => MainStatusEnum::OK,
                    ]);
                }
            } catch (\Throwable $e) {
                Asset::where('id', $n)->update(['status' => MainStatusEnum::ERROR]);
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
