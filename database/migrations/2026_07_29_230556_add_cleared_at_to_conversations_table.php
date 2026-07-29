<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('admin_cleared_at')->nullable()->after('subscriber_last_read_at');
            $table->timestamp('subscriber_cleared_at')->nullable()->after('admin_cleared_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['admin_cleared_at', 'subscriber_cleared_at']);
        });
    }
};
