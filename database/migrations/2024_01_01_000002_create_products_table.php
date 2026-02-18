<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // Иерархия: parent_id указывает на основную запись товара
            // null = основная запись; не null = алиас/синоним
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete()
                ->comment('ID основного товара (для алиасов и синонимов)');

            // Иконка — один или несколько эмодзи (например: 🔖 или 🌡🎆)
            $table->string('icon', 50)->nullable()->comment('Эмодзи иконка товара');

            // Название как в объявлении
            $table->string('name', 500)->comment('Оригинальное название товара');

            // Нормализованное название для поиска (нижний регистр, без эмодзи, без лишних пробелов)
            $table->string('normalized_name', 500)->comment('Нормализованное название для поиска');

            // Статус — нужно ли объединить с другим товаром
            $table->enum('status', ['ok', 'needs_merge'])
                ->default('ok')
                ->comment('ok = готово; needs_merge = возможный дубль, нужна проверка');

            $table->timestamps();

            $table->index('normalized_name');
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
