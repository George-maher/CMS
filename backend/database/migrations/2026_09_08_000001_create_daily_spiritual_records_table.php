<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_spiritual_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->date('activity_date');
            $table->boolean('attended_mass')->default(false);
            $table->boolean('confessed')->default(false);
            $table->boolean('received_communion')->default(false);
            $table->timestamps();

            $table->unique(['church_id', 'user_id', 'activity_date'], 'daily_spiritual_unique_per_church_user_date');
            $table->index(['user_id', 'activity_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_spiritual_records');
    }
};
