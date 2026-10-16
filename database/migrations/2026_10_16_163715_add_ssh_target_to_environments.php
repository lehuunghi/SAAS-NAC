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
        Schema::table('environments', function (Blueprint $table) {
            $table->string('ssh_host', 45)->nullable()->unique();
            $table->unsignedSmallInteger('ssh_port')->nullable();
            $table->string('ssh_user', 32)->nullable();
            $table->text('ssh_host_key')->nullable();
            $table->text('ssh_password')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->dropUnique(['ssh_host']);
            $table->dropColumn(['ssh_host', 'ssh_port', 'ssh_user', 'ssh_host_key', 'ssh_password']);
        });
    }
};
