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

            // Что отдаю
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedInteger('product_quantity')->default(1);

            // Что хочу получить
            $table->foreignId('exchange_product_id')->constrained('products');
            $table->unsignedInteger('exchange_product_quantity')->default(1);

            // Доплата (если есть)
            $table->unsignedBigInteger('surcharge_amount')->nullable()
                ->comment('Сумма доплаты (null если чистый обмен)');
            $table->enum('surcharge_currency', ['gold', 'cookie'])->nullable()
                ->comment('Валюта доплаты');

            // Кто доплачивает: me = я доплачиваю, them = они доплачивают
            $table->enum('surcharge_direction', ['me', 'them'])->nullable();

            $table->timestamp('posted_at');

            $table->timestamps();

            $table->index(['product_id', 'posted_at']);
            $table->index(['exchange_product_id', 'posted_at']);
            $table->index('posted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchanges');
    }
};
