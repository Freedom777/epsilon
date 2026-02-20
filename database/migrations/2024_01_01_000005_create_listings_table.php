<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tg_message_id')->nullable()->constrained('tg_messages')->nullOnDelete();
            $table->foreignId('tg_user_id')->nullable()->constrained('tg_users')->nullOnDelete();

            // Либо asset_id, либо item_id — никогда оба
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();

            $table->enum('type', ['buy', 'sell'])
                ->comment('buy = куплю, sell = продам');

            $table->unsignedBigInteger('price')->nullable();
            $table->enum('currency', ['gold', 'cookie'])->default('gold');

            $table->unsignedTinyInteger('enhancement')->nullable()
                ->comment('Заточка предмета: 1-10');

            $table->unsignedSmallInteger('durability_current')->nullable();
            $table->unsignedSmallInteger('durability_max')->nullable();

            $table->timestamp('posted_at');

            $table->enum('status', ['ok', 'suspicious', 'needs_review', 'invalid'])
                ->default('ok');

            $table->string('anomaly_reason', 500)->nullable();

            $table->timestamps();

            $table->index(['asset_id', 'type', 'currency', 'posted_at']);
            $table->index(['item_id', 'type', 'currency', 'posted_at']);
            $table->index('posted_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
