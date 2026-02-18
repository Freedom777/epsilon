<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tg_users', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tg_id')->unique()->comment('ID пользователя в Telegram');
            $table->string('username', 100)->nullable()->comment('Ник в Telegram (@username)');
            $table->string('display_name', 255)->nullable()->comment('Отображаемое имя');
            $table->string('first_name', 255)->nullable();
            $table->string('last_name', 255)->nullable();
            $table->timestamps();

            $table->index('username');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tg_users');
    }
};
