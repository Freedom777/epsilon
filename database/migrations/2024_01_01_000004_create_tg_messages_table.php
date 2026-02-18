<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tg_messages', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tg_message_id')->comment('ID сообщения в Telegram');
            $table->bigInteger('tg_chat_id')->comment('ID чата в Telegram');
            $table->foreignId('tg_user_id')->nullable()->constrained('tg_users')->nullOnDelete();

            // Полный текст сообщения
            $table->text('raw_text')->comment('Полный оригинальный текст сообщения');

            // Ссылка на сообщение в Telegram (для публичных чатов: t.me/chatname/message_id)
            $table->string('tg_link', 500)->nullable()->comment('Ссылка на сообщение в Telegram');

            // Дата сообщения в Telegram (не дата парсинга!)
            $table->timestamp('sent_at')->comment('Дата и время отправки сообщения');

            // Флаг — было ли сообщение уже распарсено
            $table->boolean('is_parsed')->default(false);

            $table->timestamps();

            $table->unique(['tg_message_id', 'tg_chat_id']);
            $table->index('sent_at');
            $table->index('is_parsed');
            $table->index('tg_chat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tg_messages');
    }
};
