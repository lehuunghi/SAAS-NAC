<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('quote_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->string('plan_code', 40);
            $table->unsignedInteger('devices');
            $table->unsignedInteger('branches');
            $table->text('requirements')->nullable();
            $table->string('status')->default('requested');
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'id']);
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quote_request_id');
            $table->string('number', 40)->unique();
            $table->string('plan_code', 40);
            $table->string('plan_name');
            $table->unsignedBigInteger('amount_vnd');
            $table->unsignedInteger('service_days');
            $table->text('terms');
            $table->string('status')->default('quoted');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'quote_request_id']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'created_at']);
            $table->foreign(['tenant_id', 'quote_request_id'])->references(['tenant_id', 'id'])->on('quote_requests')->restrictOnDelete();
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('amount_vnd');
            $table->string('reference', 120)->unique();
            $table->string('method')->default('bank_transfer');
            $table->timestamp('verified_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'verified_at']);
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('orders')->restrictOnDelete();
        });
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('subject', 180);
            $table->text('message');
            $table->string('status')->default('open');
            $table->text('reply')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });
        Schema::table('service_subscriptions', function (Blueprint $table) {
            $table->string('plan_code', 40)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_subscriptions', fn (Blueprint $table) => $table->dropColumn('plan_code'));
        foreach (['support_tickets', 'payments', 'orders', 'quote_requests'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
