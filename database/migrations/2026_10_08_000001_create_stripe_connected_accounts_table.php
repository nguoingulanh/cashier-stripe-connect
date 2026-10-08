<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('cashier-connect.tables.accounts', 'stripe_connected_accounts'), function (Blueprint $table) {
            $table->id();
            $table->morphs('connectable');
            $table->string('stripe_account_id')->unique();
            $table->string('type')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('default_currency', 3)->nullable();
            $table->string('email')->nullable();
            $table->boolean('charges_enabled')->default(false);
            $table->boolean('payouts_enabled')->default(false);
            $table->boolean('details_submitted')->default(false);
            $table->json('requirements')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('deauthorized_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('cashier-connect.tables.accounts', 'stripe_connected_accounts'));
    }
};
