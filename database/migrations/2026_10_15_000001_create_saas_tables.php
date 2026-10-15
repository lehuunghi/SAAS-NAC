<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 50)->unique();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['owner', 'admin', 'operator', 'viewer']);
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });
        Schema::create('environments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('hostname')->unique();
            $table->string('status')->default('requested');
            $table->string('driver');
            $table->string('server_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
            $table->unique('tenant_id');
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'site_id'])->references(['tenant_id', 'id'])->on('sites')->restrictOnDelete();
        });
        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('environment_id');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->string('status')->default('queued');
            $table->string('stage')->default('requested');
            $table->string('error_code')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'status']);
            $table->foreign(['tenant_id', 'environment_id'])->references(['tenant_id', 'id'])->on('environments')->restrictOnDelete();
        });
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('subject');
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_events', 'operations', 'environments', 'sites', 'memberships', 'tenants'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
