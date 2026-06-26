<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- Step 1: Backfill existing feedback.class_year_id ---
        // Old feedback stores class_years.id; new classes have different IDs.
        // Map by church_id + name (one Classe per ClassYear per church).
        if (Schema::hasTable('class_years')) {
            DB::statement("
                UPDATE feedback f
                SET class_year_id = c.id
                FROM class_years cy
                INNER JOIN classes c ON c.church_id = cy.church_id AND c.name = cy.name
                WHERE f.class_year_id = cy.id
            ");
        }

        // --- Step 2: Switch FK from class_years.id to classes.id ---
        Schema::table('feedback', function (Blueprint $table) {
            $table->dropForeign(['class_year_id']);
            $table->foreign('class_year_id')->references('id')->on('classes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('feedback', function (Blueprint $table) {
            $table->dropForeign(['class_year_id']);
            $table->foreign('class_year_id')->references('id')->on('class_years')->nullOnDelete();
        });
    }
};
