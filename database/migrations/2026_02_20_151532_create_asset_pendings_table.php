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
        Schema::create('asset_pendings', function (Blueprint $table) {
            $table->id();
            $table->text('raw_response')->nullable();      // сырой ответ из источника
            $table->string('raw_title');                   // оригинальное название
            $table->string('normalized_title');            // нормализованное для поиска
            $table->foreignId('asset_id')
                ->nullable()
                ->constrained('assets');
            $table->decimal('match_score', 5, 2)->nullable();
            $table->string('match_reason')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->enum('status', [
                'pending',
                'approved',
                'rejected',
                'merged',
            ])->default('pending');
            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('admin_comment')->nullable();
            $table->timestamps();

            $table->index('normalized_title');
            $table->index('status');
            $table->index('match_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_pendings');
    }
};
