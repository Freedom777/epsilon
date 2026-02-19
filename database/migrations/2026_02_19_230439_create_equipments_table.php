<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipments', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->text('raw_response')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('type')->nullable();
            $table->string('subtype')->nullable();
            $table->string('grade')->nullable();
            $table->string('rarity')->nullable();
            $table->text('extra')->nullable();
            $table->unsignedInteger('durability_max')->nullable();
            $table->boolean('personal')->default(false);
            $table->unsignedInteger('price')->nullable();
            $table->enum('status', ['process', 'ok', 'empty', 'error'])->default('process');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipments');
    }
};
