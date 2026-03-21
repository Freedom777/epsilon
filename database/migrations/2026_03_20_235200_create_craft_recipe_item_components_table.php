<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('craft_recipe_item_components', function (Blueprint $table) {
            $table->id();

            $table->foreignId('craft_recipe_id')->constrained('craft_recipes')->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();

            $table->unsignedSmallInteger('quantity');

            $table->timestamps();

            $table->index('craft_recipe_id');
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('craft_recipe_item_components');
    }
};
