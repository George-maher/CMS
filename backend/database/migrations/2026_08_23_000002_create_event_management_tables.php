<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('speaker_name', 255)->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['event_id', 'display_order']);
        });

        Schema::create('event_speakers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('name');
            $table->string('title', 255)->nullable();
            $table->text('bio')->nullable();
            $table->timestamps();

            $table->index('event_id');
        });

        Schema::create('event_buses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('bus_number', 50);
            $table->unsignedInteger('capacity');
            $table->string('driver_name', 255)->nullable();
            $table->string('coordinator_name', 255)->nullable();
            $table->timestamps();

            $table->index('event_id');
        });

        Schema::create('event_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('bus_id')->nullable()->constrained('event_buses')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('payment_status', 20)->default('unpaid');
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->string('attendance_status', 20)->default('not_checked_in');
            $table->dateTime('checked_in_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('qr_token', 64)->unique();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
            $table->index(['event_id', 'status']);
            $table->index('user_id');
        });

        Schema::create('event_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained('event_registrations')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method', 30)->default('cash');
            $table->dateTime('paid_at');
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('refunded')->default(false);
            $table->timestamps();

            $table->index('registration_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_payments');
        Schema::dropIfExists('event_registrations');
        Schema::dropIfExists('event_buses');
        Schema::dropIfExists('event_speakers');
        Schema::dropIfExists('event_sessions');
    }
};
