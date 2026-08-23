<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->unsignedInteger('room_number');
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('member_capacity');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['event_id', 'room_number']);
            $table->index('event_id');
        });

        Schema::create('event_room_cells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('event_rooms')->cascadeOnDelete();
            $table->unsignedInteger('cell_number');
            $table->enum('type', ['servant_reserved', 'member']);
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->unique(['room_id', 'cell_number']);
            $table->index('room_id');
        });

        Schema::create('event_accommodations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained('event_registrations')->cascadeOnDelete();
            $table->foreignId('cell_id')->constrained('event_room_cells')->restrictOnDelete();
            $table->timestamps();

            $table->unique('registration_id');
            $table->unique('cell_id');
            $table->index('registration_id');
        });

        Schema::create('event_bus_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained('event_registrations')->cascadeOnDelete();
            $table->foreignId('bus_id')->constrained('event_buses')->restrictOnDelete();
            $table->timestamps();

            $table->unique('registration_id');
            $table->index('bus_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_bus_sheets');
        Schema::dropIfExists('event_accommodations');
        Schema::dropIfExists('event_room_cells');
        Schema::dropIfExists('event_rooms');
    }
};
