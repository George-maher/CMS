<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('qr_invite_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('attended_at');
            $table->integer('points_earned')->default(10);
            $table->timestamps();

            $table->unique(['user_id', 'attended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
