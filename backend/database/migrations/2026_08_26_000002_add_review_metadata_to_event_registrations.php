<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Review metadata for event reservation requests: who approved/rejected
     * and when. Reuses the existing rejection_reason column for the reason.
     */
    public function up(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            if (! Schema::hasColumn('event_registrations', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('event_registrations', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
            if (! Schema::hasColumn('event_registrations', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('event_registrations', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            foreach (['approved_by', 'approved_at', 'rejected_by', 'rejected_at'] as $column) {
                if (Schema::hasColumn('event_registrations', $column)) {
                    if (str_ends_with($column, '_by')) {
                        try {
                            $table->dropConstrainedForeignId($column);
                        } catch (Throwable) {
                            $table->dropColumn($column);
                        }
                    } else {
                        $table->dropColumn($column);
                    }
                }
            }
        });
    }
};
