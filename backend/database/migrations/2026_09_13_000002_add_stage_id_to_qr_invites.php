<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_invites', function (Blueprint $table) {
            $table->foreignId('stage_id')->nullable()->after('class_id')
                ->constrained('stages')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->index('stage_id', 'qr_invites_stage_id_idx');
        });

        // Stage-scope existing invites so previously issued invitations stay
        // consistent with the new step-scoped model.
        //
        // 1) Prefer the stage carried by the class the invite targets. This is a
        //    correlated subquery — valid on both PostgreSQL and SQLite. When the
        //    class has no stage the subquery returns NULL and the UPDATE is a
        //    no-op for that row.
        DB::statement(
            'UPDATE qr_invites
             SET stage_id = (SELECT classes.stage_id FROM classes WHERE classes.id = qr_invites.class_id)
             WHERE class_id IS NOT NULL'
        );

        // 2) Fall back to the creator's assigned stage for invites without a
        //    target class (e.g. admin_to_servant invites issued by a stage
        //    admin, or servant_to_member invites issued by a stage-scoped
        //    servant). Rows already resolved in step 1 are preserved.
        DB::statement(
            'UPDATE qr_invites
             SET stage_id = (SELECT users.stage_id FROM users WHERE users.id = qr_invites.created_by)
             WHERE stage_id IS NULL AND created_by IS NOT NULL'
        );
    }

    public function down(): void
    {
        // Order matters, and it is the whole bug this fixes.
        //
        // `up()` creates BOTH the index `qr_invites_stage_id_idx` and the
        // foreign key on `stage_id`. The previous version of this method only
        // dropped the foreign key and the column, leaving the index in place,
        // and SQLite refuses to drop a column that an index still references:
        //
        //   SQLSTATE[HY000]: General error: 1 error in index
        //   qr_invites_stage_id_idx after drop column: no such column: stage_id
        //
        // PostgreSQL hides this because ALTER TABLE ... DROP COLUMN silently
        // drops dependent indexes, so the rollback appeared to work in
        // development and failed only on the SQLite connection.
        //
        // The index is dropped explicitly first. It is not "lost": `up()`
        // recreates it identically, so migrate -> rollback -> migrate returns
        // the schema to exactly the same state. Only the rollback path changes;
        // a database that already ran `up()` is untouched by this edit.
        Schema::table('qr_invites', function (Blueprint $table) {
            if (Schema::hasIndex('qr_invites', 'qr_invites_stage_id_idx')) {
                $table->dropIndex('qr_invites_stage_id_idx');
            }
        });

        Schema::table('qr_invites', function (Blueprint $table) {
            $table->dropForeign(['stage_id']);
        });

        Schema::table('qr_invites', function (Blueprint $table) {
            $table->dropColumn('stage_id');
        });
    }
};
