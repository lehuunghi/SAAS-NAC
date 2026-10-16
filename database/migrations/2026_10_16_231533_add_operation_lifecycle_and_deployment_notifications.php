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
        Schema::table('operations', function (Blueprint $table): void {
            $table->unsignedInteger('attempt')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->index(['status', 'started_at']);
            $table->unique(['tenant_id', 'id']);
        });
        Schema::create('deployment_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('operation_id');
            $table->foreign(['tenant_id', 'operation_id'])->references(['tenant_id', 'id'])->on('operations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt');
            $table->string('status', 30);
            $table->string('title');
            $table->string('message', 1000);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['operation_id', 'user_id', 'attempt']);
            $table->index(['tenant_id', 'user_id', 'read_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deployment_notifications');
        Schema::table('operations', function (Blueprint $table): void {
            $table->dropIndex(['status', 'started_at']);
            $table->dropUnique(['tenant_id', 'id']);
            $table->dropColumn(['attempt', 'started_at', 'finished_at']);
        });
    }
};
