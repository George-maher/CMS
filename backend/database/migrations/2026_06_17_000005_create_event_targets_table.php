<?php

use App\Models\Event;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->boolean('is_all_classes')->default(false);
            $table->foreignId('church_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['event_id', 'class_id']);
            $table->index('church_id');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->boolean('is_all_classes')->default(false)->after('is_active');
            $table->index('is_all_classes');
        });

        Event::whereNull('class_year_id')->orWhere('class_year_id', 0)->chunk(100, function ($events) {
            foreach ($events as $event) {
                DB::table('event_targets')->insert([
                    'event_id' => $event->id,
                    'class_id' => null,
                    'is_all_classes' => true,
                    'church_id' => $event->church_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Event::whereNotNull('class_year_id')->where('class_year_id', '>', 0)->chunk(100, function ($events) {
            foreach ($events as $event) {
                DB::table('event_targets')->insert([
                    'event_id' => $event->id,
                    'class_id' => $event->class_year_id,
                    'is_all_classes' => false,
                    'church_id' => $event->church_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('is_all_classes');
        });

        Schema::dropIfExists('event_targets');
    }
};
