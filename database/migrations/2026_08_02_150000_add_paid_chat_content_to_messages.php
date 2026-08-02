<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false)->after('media_url');
            $table->decimal('price', 10, 2)->nullable()->after('is_locked');
        });

        Schema::create('message_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('valor', 10, 2);
            $table->timestamps();

            $table->unique(['message_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_purchases');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['is_locked', 'price']);
        });
    }
};
