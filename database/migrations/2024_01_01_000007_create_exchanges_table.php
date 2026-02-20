<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchanges', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tg_message_id')->constrained('tg_messages')->cascadeOnDelete();
            $table->foreignId('tg_user_id')->nullable()->constrained('tg_users')->nullOnDelete();

            // Что отдаю — либо asset, либо item
            $table->foreignId('asset_id')->nullable()->constrained('assets')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->cascadeOnDelete();
            $table->unsignedInteger('product_quantity')->default(1);

            // Что хочу получить — либо asset, либо item
            $table->foreignId('exchange_asset_id')->nullable()->constrained('assets')->cascadeOnDelete();
            $table->foreignId('exchange_item_id')->nullable()->constrained('items')->cascadeOnDelete();
            $table->unsignedInteger('exchange_product_quantity')->default(1);

            // Доплата
            $table->unsignedBigInteger('surcharge_amount')->nullable()
                ->comment('Сумма доплаты (null если чистый обмен)');
            $table->enum('surcharge_currency', ['gold', 'cookie'])->nullable()
                ->comment('Валюта доплаты');
            $table->enum('surcharge_direction', ['me', 'them'])->nullable();

            $table->timestamp('posted_at');
            $table->timestamps();

            $table->index(['asset_id', 'posted_at']);
            $table->index(['item_id', 'posted_at']);
            $table->index(['exchange_asset_id', 'posted_at']);
            $table->index(['exchange_item_id', 'posted_at']);
            $table->index('posted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchanges');
    }
};
