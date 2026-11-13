<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.2: plans and generated tests belong to a testing module (unit, validation, …),
 * so one target can have a unit-test plan and a validation-test plan side by side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('testpilot_plans', function (Blueprint $table): void {
            $table->string('module', 32)->default('unit')->after('target_id')->index();
            $table->json('context')->nullable()->after('scenarios');
        });

        Schema::table('testpilot_generated_tests', function (Blueprint $table): void {
            $table->string('module', 32)->default('unit')->after('plan_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('testpilot_generated_tests', function (Blueprint $table): void {
            $table->dropIndex(['module']);
            $table->dropColumn('module');
        });

        Schema::table('testpilot_plans', function (Blueprint $table): void {
            $table->dropIndex(['module']);
            $table->dropColumn(['module', 'context']);
        });
    }
};
