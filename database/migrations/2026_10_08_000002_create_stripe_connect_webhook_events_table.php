<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('cashier-connect.tables.events', 'stripe_connect_webhook_events'), function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('type')->index();
            $table->string('stripe_account_id')->nullable()->index();
            $table->boolean('livemode')->default(false);
            $table->json('payload');
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('cashier-connect.tables.events', 'stripe_connect_webhook_events'));
    }
};
