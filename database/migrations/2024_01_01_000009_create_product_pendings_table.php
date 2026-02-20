<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_pendings', function (Blueprint $table) {
            $table->id();
            $table->text('raw_response')->nullable();
            $table->string('raw_title');
            $table->string('normalized_title');
            $table->enum('source_type', ['asset', 'item']); // к какой таблице относится
            $table->unsignedBigInteger('suggested_id')->nullable(); // id в assets или items
            $table->decimal('match_score', 5, 2)->nullable();
            $table->string('match_reason')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->enum('status', ['pending', 'approved', 'rejected', 'merged'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('admin_comment')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'normalized_title']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_pendings');
    }
};
