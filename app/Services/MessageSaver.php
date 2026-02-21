<?php

namespace App\Services;

use App\Models\Exchange;
use App\Models\Listing;
use App\Models\Service;
use App\Models\ServiceListing;
use App\Models\TgMessage;
use App\Models\TgUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MessageSaver
{
    public function __construct(
        private readonly MessageParser        $parser,
        private readonly MatchingService      $matchingService,
        private readonly PriceAnomalyDetector $anomalyDetector,
    ) {}

    public function saveRawMessage(array $msgData): TgMessage
    {
        $user = null;
        if (!empty($msgData['tg_user_id'])) {
            $user = TgUser::updateOrCreate(
                ['tg_id' => $msgData['tg_user_id']],
                [
                    'username'     => $msgData['username']     ?? null,
                    'display_name' => $msgData['display_name'] ?? null,
                    'first_name'   => $msgData['first_name']   ?? null,
                    'last_name'    => $msgData['last_name']    ?? null,
                ]
            );
        }

        return TgMessage::updateOrCreate(
            [
                'tg_message_id' => $msgData['tg_message_id'],
                'tg_chat_id'    => $msgData['tg_chat_id'],
            ],
            [
                'tg_user_id' => $user?->id,
                'raw_text'   => $msgData['raw_text'],
                'tg_link'    => $msgData['tg_link'] ?? null,
                'sent_at'    => $msgData['sent_at'],
                'is_parsed'  => false,
            ]
        );
    }

    public function parseAndSave(TgMessage $message): void
    {
        if (empty(trim($message->raw_text))) {
            $message->update(['is_parsed' => true]);
            return;
        }

        $parsed = $this->parser->parse($message->raw_text);

        DB::transaction(function () use ($message, $parsed) {
            foreach ($parsed['listings'] as $item) {
                $this->saveListing($message, $item);
            }

            foreach ($parsed['exchanges'] as $exchange) {
                $this->saveExchange($message, $exchange);
            }

            foreach ($parsed['service_listings'] as $service) {
                $this->saveServiceListing($message, $service);
            }

            $message->update(['is_parsed' => true]);
        });
    }

    private function saveListing(TgMessage $message, array $item): void
    {
        try {
            $match = $this->matchingService->match($item['name'], $item['grade'] ?? null);

            Log::debug('Matching result', [
                'name'  => $item['name'],
                'grade' => $item['grade'] ?? null,
                'match' => $match ? [
                    'source_type' => $match->sourceType,
                    'id'          => $match->id,
                    'match_type'  => $match->matchType,
                    'score'       => $match->score,
                ] : null,
            ]);

            // Товар не найден — ушёл в product_pendings, листинг не создаём
            if (!$match) {
                return;
            }

            $status        = 'ok';
            $anomalyReason = null;

            if (!empty($item['price'])) {
                $anomaly       = $this->anomalyDetector->check(
                    $match->id,
                    $match->sourceType,
                    $item['type'],
                    $item['currency'],
                    $item['price'],
                );
                $status        = $anomaly['status'];
                $anomalyReason = $anomaly['reason'];
            }

            Listing::create([
                'tg_message_id'      => $message->id,
                'tg_user_id'         => $message->tg_user_id,
                'asset_id'           => $match->isAsset() ? $match->id : null,
                'item_id'            => $match->isItem()  ? $match->id : null,
                'type'               => $item['type'],
                'price'              => $item['price']              ?? null,
                'currency'           => $item['currency']           ?? 'gold',
                'enhancement'        => $item['enhancement']        ?? null,
                'durability_current' => $item['durability_current'] ?? null,
                'durability_max'     => $item['durability_max']     ?? null,
                'posted_at'          => $message->sent_at,
                'status'             => $status,
                'anomaly_reason'     => $anomalyReason,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error saving listing', [
                'message_id' => $message->id,
                'item'       => $item,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function saveExchange(TgMessage $message, array $exchange): void
    {
        try {
            if (empty($exchange['give_name']) || empty($exchange['want_name'])) {
                return;
            }

            $giveMatch = $this->matchingService->match(
                $exchange['give_name'],
                $exchange['give_grade'] ?? null,
            );

            $wantMatch = $this->matchingService->match(
                $exchange['want_name'],
                $exchange['want_grade'] ?? null,
            );

            // Сохраняем только если оба товара найдены
            if (!$giveMatch || !$wantMatch) {
                return;
            }

            Exchange::create([
                'tg_message_id'             => $message->id,
                'tg_user_id'                => $message->tg_user_id,
                'asset_id'                  => $giveMatch->isAsset() ? $giveMatch->id : null,
                'item_id'                   => $giveMatch->isItem()  ? $giveMatch->id : null,
                'product_quantity'          => $exchange['give_qty']            ?? 1,
                'exchange_asset_id'         => $wantMatch->isAsset() ? $wantMatch->id : null,
                'exchange_item_id'          => $wantMatch->isItem()  ? $wantMatch->id : null,
                'exchange_product_quantity' => $exchange['want_qty']            ?? 1,
                'surcharge_amount'          => $exchange['surcharge']           ?? null,
                'surcharge_currency'        => $exchange['surcharge_currency']  ?? null,
                'surcharge_direction'       => $exchange['surcharge_direction'] ?? null,
                'posted_at'                 => $message->sent_at,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error saving exchange', [
                'message_id' => $message->id,
                'exchange'   => $exchange,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function saveServiceListing(TgMessage $message, array $item): void
    {
        try {
            if (blank($item['name'])) {
                return;
            }

            $service = Service::findOrCreateByName($item['name'], $item['icon'] ?? null);

            ServiceListing::create([
                'tg_message_id' => $message->id,
                'tg_user_id'    => $message->tg_user_id,
                'service_id'    => $service->id,
                'type'          => $item['type']        ?? 'offer',
                'price'         => $item['price']       ?? null,
                'currency'      => $item['currency']    ?? 'gold',
                'description'   => $item['description'] ?? null,
                'posted_at'     => $message->sent_at,
                'status'        => 'ok',
            ]);
        } catch (\Throwable $e) {
            Log::error('Error saving service listing', [
                'message_id' => $message->id,
                'item'       => $item,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
