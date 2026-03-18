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
            $table->foreignId('location_id')->nullable()->constrained('locations')->cascadeOnUpdate()->nullOnDelete();
            $table->string('title')->nullable()->collation('utf8mb4_bin');
            $table->unsignedSmallInteger('level')->nullable();
            $table->string('city')->nullable();
            $table->string('location')->nullable();
            $table->unsignedSmallInteger('exp')->nullable();
            $table->unsignedSmallInteger('gold')->nullable();
            $table->enum('status', ['process', 'ok', 'empty', 'error'])->default('process');
            $table->timestamps();
            $table->text('raw_response')->nullable();
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
