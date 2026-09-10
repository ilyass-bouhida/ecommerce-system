<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ONE primary website connection. Secrets use encrypted casts.
     */
    public function up(): void
    {
        Schema::create('website_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Website');
            $table->string('website_url')->nullable();
            $table->string('api_base_url')->nullable();
            $table->text('api_key')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('connection_status', 20)->default('NOT_CONFIGURED');
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_connections');
    }
};
