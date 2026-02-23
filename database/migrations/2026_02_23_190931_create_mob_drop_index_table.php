<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mob_drop_index', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mob_id');
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->string('drop_text');   // оригинальная строка из drop_asset
            $table->string('normalized'); // нормализованное название для поиска
            $table->timestamps();

            $table->foreign('mob_id')->references('id')->on('mobs')->onDelete('cascade');
            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('set null');

            $table->index('normalized');
            $table->index('mob_id');
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mob_drop_index');
    }
};
