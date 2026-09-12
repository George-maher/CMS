<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('scope', 20)->default('self')->after('role')->index();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('stage_id')->nullable()->after('class_id')
                ->constrained('stages')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        // Backfill organizational scope from the current role. At the time this
        // migration runs there are no stage_admin users yet, so only the three
        // existing role defaults are written.
        DB::table('users')->whereIn('role', ['admin', 'assistant_admin'])->update(['scope' => 'church']);
        DB::table('users')->where('role', 'servant')->update(['scope' => 'class']);
        DB::table('users')->where('role', 'member')->update(['scope' => 'self']);

        // The stages table has no (church_id, name) uniqueness constraint yet.
        // Merge any duplicates onto the lowest id (reassigning child rows) before
        // enforcing a unique index so existing production data can migrate safely.
        $this->mergeDuplicateStages();

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS stages_church_id_name_unique ON stages (church_id, name)');
        } elseif (! Schema::hasIndex('stages', 'stages_church_id_name_unique')) {
            Schema::table('stages', function (Blueprint $table) {
                $table->unique(['church_id', 'name'], 'stages_church_id_name_unique');
            });
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS stages_church_id_name_unique');
        } elseif (Schema::hasIndex('stages', 'stages_church_id_name_unique')) {
            Schema::table('stages', function (Blueprint $table) {
                $table->dropUnique('stages_church_id_name_unique');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['stage_id']);
            $table->dropColumn('stage_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_scope_index');
            $table->dropColumn('scope');
        });
    }

    private function mergeDuplicateStages(): void
    {
        $duplicates = DB::table('stages')
            ->select('church_id', 'name')
            ->groupBy('church_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $keep = DB::table('stages')
                ->where('church_id', $duplicate->church_id)
                ->where('name', $duplicate->name)
                ->orderBy('id')
                ->first(['id']);

            $removeIds = DB::table('stages')
                ->where('church_id', $duplicate->church_id)
                ->where('name', $duplicate->name)
                ->where('id', '!=', $keep->id)
                ->pluck('id');

            DB::table('classes')->whereIn('stage_id', $removeIds)->update(['stage_id' => $keep->id]);
            DB::table('users')->whereIn('stage_id', $removeIds)->update(['stage_id' => $keep->id]);
            DB::table('stages')->whereIn('id', $removeIds)->delete();
        }
    }
};
