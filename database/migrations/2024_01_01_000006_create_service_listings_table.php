<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_listings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tg_message_id')->constrained('tg_messages')->cascadeOnDelete();
            $table->foreignId('tg_user_id')->nullable()->constrained('tg_users')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();

            // offer = предлагаю услугу, wanted = ищу исполнителя/нанимаю
            $table->enum('type', ['offer', 'wanted'])
                ->comment('offer = предлагаю услугу; wanted = ищу исполнителя / нанимаю');

            $table->unsignedBigInteger('price')->nullable();
            $table->enum('currency', ['gold', 'cookie'])->default('gold')->nullable();

            // Описание услуги (свободный текст из объявления)
            $table->text('description')->nullable();

            $table->timestamp('posted_at');

            $table->enum('status', ['ok', 'suspicious', 'needs_review', 'invalid'])->default('ok');
            $table->string('anomaly_reason', 500)->nullable();

            $table->timestamps();

            $table->index(['service_id', 'type', 'posted_at']);
            $table->index('posted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_listings');
    }
};
