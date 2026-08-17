<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained('users')->cascadeOnDelete();
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->unique(['admin_id', 'subscriber_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_memories');
    }
};
