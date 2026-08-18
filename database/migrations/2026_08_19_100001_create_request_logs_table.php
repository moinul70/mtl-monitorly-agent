<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_logs', function (Blueprint $table) {
            $table->id();
            $table->string('method', 10);
            $table->string('path');
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('response_ms');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Fixes issue #1: the read query filters by created_at and often
            // also filters/groups by status_code — index both so it stays
            // fast as the table grows instead of full-scanning.
            $table->index('created_at');
            $table->index('status_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_logs');
    }
};
