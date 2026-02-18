<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products_pending', function (Blueprint $table) {
            $table->id();

            // Ссылка на существующий товар (null = новый товар, не null = конфликт)
            $table->foreignId('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();

            // Данные из объявления
            $table->string('icon', 50)->nullable();
            $table->string('name', 500);
            $table->string('normalized_name', 500);
            $table->enum('grade', ['I', 'II', 'III', 'III+', 'IV', 'V'])->nullable();

            // Причина попадания в очередь
            $table->enum('status', [
                'new',            // новый товар, не найден в products
                'icon_conflict',  // иконка отличается от той что в products
                'grade_conflict', // грейд отличается от того что в products
                'missing_icon',   // товар найден, но иконки нет ни в БД ни в объявлении
                'missing_grade',  // товар найден, но грейда нет ни в БД ни в объявлении
            ])->default('new');

            // Из какого сообщения пришло
            $table->foreignId('tg_message_id')
                ->nullable()
                ->constrained('tg_messages')
                ->nullOnDelete();

            // Просмотрено ли администратором
            $table->boolean('reviewed')->default(false);

            $table->timestamps();

            $table->index(['status', 'reviewed']);
            $table->index('normalized_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products_pending');
    }
};
