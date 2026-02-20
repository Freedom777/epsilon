<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mobs', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->text('raw_response')->nullable();
            $table->string('title')->nullable();
            $table->unsignedSmallInteger('level')->nullable();
            $table->string('city')->nullable();
            $table->string('location')->nullable();
            $table->unsignedInteger('exp')->nullable();
            $table->unsignedInteger('gold')->nullable();
            $table->json('drop')->nullable();
            $table->text('extra')->nullable();
            $table->enum('status', ['process', 'ok', 'empty', 'error'])->default('process');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mobs');
    }
};
