<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lives', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->string('titulo');
            $table->text('descricao')->nullable();
            $table->timestamp('starts_at');
            $table->boolean('is_private')->default(false);
            $table->unsignedInteger('price_credits')->default(0);
            $table->unsignedInteger('max_participants')->default(50);
            $table->string('status', 20)->default('agendada');
            $table->boolean('chat_enabled')->default(true);
            $table->string('livekit_room')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
        });

        Schema::create('live_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_id')->constrained('lives')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['live_id', 'user_id']);
        });

        Schema::create('live_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_id')->constrained('lives')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20)->default('viewer');
            $table->boolean('chat_muted')->default(false);
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('kicked_at')->nullable();
            $table->timestamps();

            $table->unique(['live_id', 'user_id']);
        });

        Schema::create('live_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_id')->constrained('lives')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('credits_paid')->default(0);
            $table->timestamps();

            $table->unique(['live_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_tickets');
        Schema::dropIfExists('live_participants');
        Schema::dropIfExists('live_invites');
        Schema::dropIfExists('lives');
    }
};
