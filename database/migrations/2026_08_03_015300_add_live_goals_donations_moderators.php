<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_participants', function (Blueprint $table) {
            $table->boolean('is_moderator')->default(false)->after('role');
        });

        Schema::create('live_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_id')->constrained('lives')->cascadeOnDelete();
            $table->string('titulo');
            $table->unsignedInteger('target_credits');
            $table->unsignedInteger('current_credits')->default(0);
            $table->boolean('hidden_by_admin')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['live_id', 'sort_order']);
        });

        Schema::create('live_donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_id')->constrained('lives')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('live_goal_id')->nullable()->constrained('live_goals')->nullOnDelete();
            $table->unsignedInteger('credits');
            $table->timestamps();

            $table->index(['live_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_donations');
        Schema::dropIfExists('live_goals');

        Schema::table('live_participants', function (Blueprint $table) {
            $table->dropColumn('is_moderator');
        });
    }
};
