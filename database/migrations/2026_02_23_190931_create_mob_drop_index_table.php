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
            $table->foreignId('mob_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('drop_text');   // оригинальная строка из drop_asset
            $table->string('normalized'); // нормализованное название для поиска

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
