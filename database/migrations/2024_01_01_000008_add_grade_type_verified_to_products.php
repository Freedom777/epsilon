<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('grade', ['I', 'II', 'III', 'III+', 'IV', 'V'])
                ->nullable()
                ->after('icon')
                ->comment('Грейд предмета');

            $table->enum('type', [
                'weapon',
                'armor',
                'jewelry',
                'scroll',
                'recipe',
                'consumable',
                'resource',
                'talent',
                'appearance',
                'chest',
                'other',
            ])
                ->nullable()
                ->after('grade')
                ->comment('Тип товара');

            $table->boolean('is_verified')
                ->default(false)
                ->after('status')
                ->comment('Подтверждено администратором по игровой базе данных');

            $table->index(['normalized_name', 'grade']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['normalized_name', 'grade']);
            $table->dropColumn(['grade', 'type', 'is_verified']);
        });
    }
};
