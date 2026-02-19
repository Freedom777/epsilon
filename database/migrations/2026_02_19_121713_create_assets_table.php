<?php

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
            $table->text('description')->nullable();     // остальной текст
            $table->enum('status', ['process', 'ok', 'empty', 'error'])->default('process');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
