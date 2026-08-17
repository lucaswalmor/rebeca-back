<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('scope', 20)->default('selected');
            $table->text('system_prompt')->nullable();
            $table->unsignedSmallInteger('reply_delay_minutes')->default(5);
            $table->unsignedSmallInteger('takeover_minutes')->default(15);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_settings');
    }
};
