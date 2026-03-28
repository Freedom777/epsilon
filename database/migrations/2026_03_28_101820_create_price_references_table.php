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
        Schema::create('price_references', function (Blueprint $table) {
            $table->id();

            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();

            // Цены покупки (максимальная цена, по которой покупают)
            $table->unsignedInteger('buy_min')->nullable();
            $table->unsignedInteger('buy_avg')->nullable();
            $table->unsignedInteger('buy_max')->nullable();

            // Цены продажи (минимальная цена, по которой продают)
            $table->unsignedInteger('sell_min')->nullable();
            $table->unsignedInteger('sell_avg')->nullable();
            $table->unsignedInteger('sell_max')->nullable();

            $table->unsignedInteger('sample_count')->default(0);
            $table->unsignedSmallInteger('period_days')->default(30);
            $table->boolean('is_manual')->default(false);
            $table->text('admin_note')->nullable();

            $table->timestamps();

            // Один товар — одна запись
            $table->unique('item_id');
            $table->unique('asset_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_references');
    }
};
