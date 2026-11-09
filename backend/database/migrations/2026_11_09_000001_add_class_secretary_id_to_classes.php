<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the class-secretary assignment to `classes`.
 *
 * A single nullable foreign key is used deliberately instead of a pivot or a
 * `class_responsibilities` table:
 *
 *  - one column per class can physically hold only one secretary, so "at most
 *    one active class secretary" is a schema invariant rather than a rule that
 *    every future write path has to remember;
 *  - `NULL` means "no secretary", which is the safe default for every existing
 *    class, so this migration is purely additive and needs no backfill;
 *  - deleting the user clears the assignment instead of leaving a dangling id.
 *
 * The reverse direction (which class does this user lead?) is served by the
 * `Classe::secretary()` relation and `User::secretaryClasses()`.
 *
 * Driver note: on SQLite the column is added with a plain `ALTER TABLE ... ADD
 * COLUMN` so the table is not rebuilt. A rebuild would strip the named
 * composite tenant foreign key (`classes_church_stage_fk`) that the previous
 * migration installed, and would make `TenantSchemaVerificationTest` fail.
 * PostgreSQL keeps the full Laravel-level foreign key + index definition.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement(
                'ALTER TABLE classes ADD COLUMN class_secretary_id integer '
                .'REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE'
            );
            DB::statement(
                'CREATE INDEX IF NOT EXISTS classes_class_secretary_id_idx '
                .'ON classes (class_secretary_id)'
            );

            return;
        }

        Schema::table('classes', function (Blueprint $table) {
            $table->foreignId('class_secretary_id')->nullable()->after('stage_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->index('class_secretary_id', 'classes_class_secretary_id_idx');
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS classes_class_secretary_id_idx');
            DB::statement('ALTER TABLE classes DROP COLUMN class_secretary_id');

            return;
        }

        Schema::table('classes', function (Blueprint $table) {
            $table->dropIndex('classes_class_secretary_id_idx');
            $table->dropForeign(['class_secretary_id']);
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn('class_secretary_id');
        });
    }
};
