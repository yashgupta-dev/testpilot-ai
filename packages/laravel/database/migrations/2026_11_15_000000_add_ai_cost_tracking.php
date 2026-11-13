<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('testpilot_ai_requests', function (Blueprint $table): void {
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('saved_prompt_cache_micros')->default(0);
            $table->unsignedBigInteger('saved_response_cache_micros')->default(0);
            $table->unsignedBigInteger('saved_batch_micros')->default(0);
            $table->boolean('response_cache_hit')->default(false);
            $table->boolean('batch')->default(false);
            $table->unsignedSmallInteger('attempt')->nullable();
        });

        Schema::create('testpilot_ai_batches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('provider_batch_id')->nullable()->index();
            $table->string('module', 16);
            $table->string('status', 16)->index(); // in_progress | ended | failed
            $table->unsignedInteger('request_count')->default(0);
            $table->json('counts')->nullable();
            $table->string('created_by')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::table('testpilot_plans', function (Blueprint $table): void {
            $table->string('ai_batch_id', 26)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('testpilot_plans', fn (Blueprint $table) => $table->dropColumn('ai_batch_id'));
        Schema::dropIfExists('testpilot_ai_batches');
        Schema::table('testpilot_ai_requests', fn (Blueprint $table) => $table->dropColumn([
            'cache_write_tokens', 'cache_read_tokens', 'saved_prompt_cache_micros', 'saved_response_cache_micros',
            'saved_batch_micros', 'response_cache_hit', 'batch', 'attempt',
        ]));
    }
};
