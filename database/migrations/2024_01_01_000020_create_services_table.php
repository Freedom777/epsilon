<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();

            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('services')
                ->nullOnDelete()
                ->comment('ID основной услуги (для алиасов и синонимов)');

            $table->string('icon', 50)->nullable()->comment('Эмодзи иконка услуги');
            $table->string('name', 500)->comment('Оригинальное название услуги');
            $table->string('normalized_name', 500)->comment('Нормализованное название для поиска');

            $table->enum('status', ['ok', 'needs_merge'])->default('ok');

            $table->timestamps();

            $table->index('normalized_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
