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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false);
            $table->boolean('is_active')->default(true);
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('contact_name', 120)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('tax_code', 40)->nullable();
            $table->string('address', 500)->nullable();
        });
        Schema::table('environments', function (Blueprint $table) {
            $table->string('release_url', 500)->nullable();
            $table->string('release_sha256', 64)->nullable();
            $table->text('deployment_notes')->nullable();
            $table->timestamp('handed_over_at')->nullable();
            $table->text('acceptance_evidence')->nullable();
        });
        Schema::create('admin_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action');
            $table->string('subject');
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_audit_events');
        Schema::table('environments', fn (Blueprint $table) => $table->dropColumn(['release_url', 'release_sha256', 'deployment_notes', 'handed_over_at', 'acceptance_evidence']));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn(['contact_name', 'phone', 'tax_code', 'address']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['is_platform_admin', 'is_active']));
    }
};
