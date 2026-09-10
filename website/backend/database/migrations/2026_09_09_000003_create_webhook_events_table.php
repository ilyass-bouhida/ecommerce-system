<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw inbound webhook envelope store. Unique (source, external_event_id)
     * gives idempotency when the sender provides a stable event id
     * (MySQL permits repeated NULLs, so id-less events stay insertable).
     */
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('source', 30)->default('website');
            $table->string('external_event_id')->nullable();
            $table->string('event_type', 100);
            $table->json('payload');
            $table->string('status', 20)->default('RECEIVED');
            $table->integer('attempts')->default(0);
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_event_id'], 'webhook_source_external_unique');
            $table->index('status');
            $table->index('event_type');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
