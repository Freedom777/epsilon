<?php

namespace App\Services;

use App\Models\Exchange;
use App\Models\Listing;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceListing;
use App\Models\TgMessage;
use App\Models\TgUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MessageSaver
{
    public function __construct(
        private readonly MessageParser $parser,
        private readonly PriceAnomalyDetector $anomalyDetector
    ) {}

    // =========================================================================
    // Публичный API
    // =========================================================================

    /**
     * Сохранить сырое сообщение в tg_messages.
     */
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

    /**
     * Распарсить и сохранить объявления из сообщения.
     */
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

    // =========================================================================
    // Приватные методы
    // =========================================================================

    private function saveListing(TgMessage $message, array $item): void
    {
        try {
            // Ищем товар через findOrQueue (с поддержкой grade)
            $product = Product::findOrQueue(
                rawName:      $item['name'],
                grade:        $item['grade']  ?? null,
                icon:         $item['icon']   ?? null,
                tgMessageId:  $message->id,
            );

            // Если товар не найден — он ушёл в products_pending на модерацию
            // Листинг сохраняем с needs_review
            $status        = $product ? 'ok' : 'needs_review';
            $anomalyReason = null;

            // Проверка аномалии цены (только если товар найден и цена есть)
            if ($product && !empty($item['price'])) {
                $anomaly       = $this->anomalyDetector->check(
                    $product->effective_id,
                    $item['type'],
                    $item['currency'],
                    $item['price']
                );
                $status        = $anomaly['status'];
                $anomalyReason = $anomaly['reason'];
            }

            Listing::create([
                'tg_message_id'      => $message->id,
                'tg_user_id'         => $message->tg_user_id,
                'product_id'         => $product?->id,
                'type'               => $item['type'],
                'price'              => $item['price']              ?? null,
                'currency'           => $item['currency']           ?? 'gold',
                'quantity'           => $item['quantity']           ?? null,
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

            $giveProduct = Product::findOrQueue(
                rawName:     $exchange['give_name'],
                grade:       $exchange['give_grade'] ?? null,
                icon:        $exchange['give_icon']  ?? null,
                tgMessageId: $message->id,
            );

            $wantProduct = Product::findOrQueue(
                rawName:     $exchange['want_name'],
                grade:       $exchange['want_grade'] ?? null,
                icon:        $exchange['want_icon']  ?? null,
                tgMessageId: $message->id,
            );

            // Сохраняем только если оба товара найдены
            if (!$giveProduct || !$wantProduct) {
                return;
            }

            Exchange::create([
                'tg_message_id'             => $message->id,
                'tg_user_id'                => $message->tg_user_id,
                'product_id'                => $giveProduct->id,
                'product_quantity'          => $exchange['give_qty']           ?? 1,
                'exchange_product_id'       => $wantProduct->id,
                'exchange_product_quantity' => $exchange['want_qty']           ?? 1,
                'surcharge_amount'          => $exchange['surcharge']          ?? null,
                'surcharge_currency'        => $exchange['surcharge_currency'] ?? null,
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
