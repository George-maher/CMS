<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->text('medical_notes')->nullable()->after('notes');
            $table->text('booking_with')->nullable()->after('medical_notes');
            $table->text('rejection_reason')->nullable()->after('booking_with');
        });
    }

    public function down(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->dropColumn(['medical_notes', 'booking_with', 'rejection_reason']);
        });
    }
};
