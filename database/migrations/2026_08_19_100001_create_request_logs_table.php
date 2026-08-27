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
            $table->string('project_name', 200);
            $table->string('method', 10);
            $table->string('path');
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('response_ms');
            $table->decimal('memory_mb', 10, 2)->default(0);
            $table->decimal('peak_memory_mb', 10, 2)->default(0);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('status_code');
            $table->index('project_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_logs');
    }
};
