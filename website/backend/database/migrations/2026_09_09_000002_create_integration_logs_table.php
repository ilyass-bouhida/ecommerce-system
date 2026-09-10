<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only integration activity. Never stores secrets.
     * actor_user_id is informational only — NO cross-service foreign key.
     */
    public function up(): void
    {
        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 10);
            $table->string('event_type', 50)->nullable();
            $table->string('reference_type', 50)->nullable();
            $table->string('reference_id', 100)->nullable();
            $table->string('status', 10);
            $table->string('http_method', 10)->nullable();
            $table->string('endpoint', 2048)->nullable();
            $table->integer('http_status')->nullable();
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('direction');
            $table->index('event_type');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
    }
};
