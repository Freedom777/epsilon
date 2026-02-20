<?php

use App\Enums\ItemGradeEnum;
use App\Enums\ItemRarityEnum;
use App\Enums\ItemSubtypeEnum;
use App\Enums\ItemTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->text('raw_response')->nullable();
            $table->string('title')->nullable();
            $table->string('normalized_title')->nullable();
            $table->text('description')->nullable();
            $table->enum('type', array_column(ItemTypeEnum::cases(), 'value'))->nullable();
            $table->enum('subtype', array_column(ItemSubtypeEnum::cases(), 'value'))->nullable();
            $table->enum('grade', array_column(ItemGradeEnum::cases(), 'value'))->nullable();
            $table->enum('rarity', array_column(ItemRarityEnum::cases(), 'value'))->nullable();
            $table->text('extra')->nullable();
            $table->unsignedInteger('durability_max')->nullable();
            $table->unsignedInteger('price')->nullable();
            $table->boolean('is_personal')->default(false);
            $table->boolean('is_event')->default(false);
            $table->enum('status', ['process', 'ok', 'empty', 'error'])->default('process');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
