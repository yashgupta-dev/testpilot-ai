<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('testpilot_settings', function (Blueprint $table): void {
            $table->string('key', 100)->primary();
            $table->text('value');          // JSON; secrets are encrypted with the app key
            $table->boolean('secret')->default(false);
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('testpilot_settings');
    }
};
