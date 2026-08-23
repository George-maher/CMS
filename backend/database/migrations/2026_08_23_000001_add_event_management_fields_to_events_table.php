<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->after('type');
            $table->date('end_date')->nullable()->after('event_date');
            $table->time('start_time')->nullable()->after('end_date');
            $table->time('end_time')->nullable()->after('start_time');
            $table->unsignedInteger('max_capacity')->nullable()->after('end_time');

            // Conference-specific
            $table->string('theme', 255)->nullable()->after('max_capacity');
            $table->string('target_age_group', 100)->nullable();
            $table->string('target_group', 255)->nullable();

            // Trip-specific
            $table->string('destination', 255)->nullable();
            $table->string('departure_location', 255)->nullable();
            $table->dateTime('departure_at')->nullable();
            $table->dateTime('return_at')->nullable();
            $table->string('transportation_type', 100)->nullable();
            $table->string('coordinator_name', 255)->nullable();
            $table->string('coordinator_phone', 30)->nullable();
            $table->decimal('price_per_participant', 10, 2)->default(0)->after('transportation_type');

            $table->index('status');
        });

        // Existing published events keep working as open events.
        DB::table('events')->where('is_active', true)->update(['status' => 'open']);
        DB::table('events')->where('is_active', false)->update(['status' => 'closed']);
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $columns = [
                'status',
                'end_date',
                'start_time',
                'end_time',
                'max_capacity',
                'theme',
                'target_age_group',
                'target_group',
                'destination',
                'departure_location',
                'departure_at',
                'return_at',
                'transportation_type',
                'coordinator_name',
                'coordinator_phone',
                'price_per_participant',
            ];
            $table->dropColumn($columns);
        });
    }
};
