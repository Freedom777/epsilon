<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('craft_recipes', function (Blueprint $table) {
            $table->id();

            // Результат крафта: item ИЛИ asset (один из двух заполнен)
            $table->foreignId('item_id')->nullable()->constrained('items')->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->cascadeOnDelete();

            $table->foreignId('npc_id')->nullable()->constrained('npcs')->nullOnDelete();

            $table->string('craft_level')->nullable();              // "Грандмастер 2"
            $table->unsignedSmallInteger('energy_cost')->nullable(); // 12

            $table->timestamps();

            $table->index('item_id');
            $table->index('asset_id');
            $table->index('npc_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('craft_recipes');
    }
};
