<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('testpilot_targets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key_hash', 64)->unique();
            $table->text('key');
            $table->string('type', 16);
            $table->string('class')->nullable();
            $table->string('method')->nullable();
            $table->string('function')->nullable();
            $table->string('file');
            $table->unsignedInteger('line');
            $table->unsignedInteger('end_line');
            $table->text('signature');
            $table->string('fingerprint', 80);
            $table->unsignedSmallInteger('complexity')->default(1);
            $table->string('area')->nullable()->index();
            $table->string('criticality', 32)->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('modules');
            $table->json('config');
            $table->json('data');
            $table->longText('source');
            $table->string('status', 32)->default('not_planned')->index();
            $table->unsignedTinyInteger('risk_score')->default(0)->index();
            $table->string('risk_level', 16)->default('low');
            $table->json('risk_reasons');
            $table->json('route')->nullable();
            $table->string('last_result', 16)->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('removed_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('testpilot_plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('target_id')->constrained('testpilot_targets')->cascadeOnDelete();
            $table->string('status', 32)->default('proposed')->index();
            $table->json('scenarios');
            $table->text('notes')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version', 16)->nullable();
            $table->string('target_fingerprint', 80);
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('testpilot_generated_tests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('target_id')->constrained('testpilot_targets')->cascadeOnDelete();
            $table->foreignUlid('plan_id')->nullable()->constrained('testpilot_plans')->nullOnDelete();
            $table->string('status', 32)->index();
            $table->longText('code')->nullable();
            $table->string('pending_path')->nullable();
            $table->string('approved_path')->nullable();
            $table->json('verification')->nullable();
            $table->decimal('mutation_score', 5, 2)->nullable();
            $table->unsignedInteger('fuzz_seed')->nullable();
            $table->string('model')->nullable();
            $table->unsignedTinyInteger('repair_attempts')->default(0);
            $table->string('source_fingerprint', 80);
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('testpilot_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedInteger('number')->unique();
            $table->string('status', 16)->index();
            $table->string('trigger', 16);
            $table->string('idempotency_key', 64)->index();
            $table->string('git_sha', 40)->nullable();
            $table->json('target_ids')->nullable();
            $table->unsignedInteger('passed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('assertions')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('command')->nullable();
            $table->longText('output')->nullable();
            $table->text('error')->nullable();
            $table->string('started_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('testpilot_run_results', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('testpilot_runs')->cascadeOnDelete();
            $table->foreignUlid('target_id')->nullable()->constrained('testpilot_targets')->nullOnDelete();
            $table->foreignUlid('generated_test_id')->nullable()->constrained('testpilot_generated_tests')->nullOnDelete();
            $table->string('scenario_id', 8)->nullable();
            $table->text('name');
            $table->string('status', 16)->index();
            $table->decimal('time_ms', 10, 2)->default(0);
            $table->unsignedInteger('assertions')->default(0);
            $table->text('message')->nullable();
            $table->text('details')->nullable();
            $table->timestamps();
        });

        Schema::create('testpilot_ai_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('purpose', 32);
            $table->string('provider', 32);
            $table->string('model');
            $table->string('prompt_version', 16)->nullable();
            $table->text('target_key')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('status', 16);
            $table->text('error')->nullable();
            $table->timestamp('created_at')->index();
        });

        Schema::create('testpilot_audit_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('actor')->nullable();
            $table->string('action', 64)->index();
            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('context')->nullable();
            $table->string('ip', 64)->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('testpilot_audit_logs');
        Schema::dropIfExists('testpilot_ai_requests');
        Schema::dropIfExists('testpilot_run_results');
        Schema::dropIfExists('testpilot_runs');
        Schema::dropIfExists('testpilot_generated_tests');
        Schema::dropIfExists('testpilot_plans');
        Schema::dropIfExists('testpilot_targets');
    }
};
