<?php

use App\Enums\AssetSkillTypeEnum;
use App\Enums\ItemGradeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary(); // это наш N
            $table->text('raw_response')->nullable();    // сырой ответ от бота
            $table->string('title')->nullable();         // первая строка (название)
            $table->string('normalized_title')->nullable();
            $table->string('type', 50)->nullable();         // тип
            $table->string('subtype', 50)->nullable();      // подтип
            $table->enum('grade', array_column(ItemGradeEnum::cases(), 'value'))->nullable();
            $table->enum('skill_type', array_column(AssetSkillTypeEnum::cases(), 'value'))->nullable();
            $table->text('description')->nullable();     // остальной текст
            $table->text('drop_monster')->nullable();    // дроп с монстров
            $table->boolean('is_personal')->default(false);
            $table->boolean('is_event')->default(false);
            $table->enum('status', ['process', 'ok', 'empty', 'error'])->default('process');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
