<?php

use App\Models\QRInvite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_invites', function (Blueprint $table) {
            $table->foreignId('class_id')->nullable()->after('created_by')
                ->constrained('classes')->nullOnDelete();
            $table->index('class_id');
        });

        QRInvite::whereNotNull('class_year_id')->chunk(100, function ($invites) {
            foreach ($invites as $invite) {
                $classYear = \DB::table('class_years')->find($invite->class_year_id);
                if (!$classYear) continue;
                $classe = \DB::table('classes')
                    ->where('name', $classYear->name)
                    ->where('church_id', $invite->church_id)
                    ->first();
                if ($classe) {
                    \DB::table('qr_invites')
                        ->where('id', $invite->id)
                        ->update(['class_id' => $classe->id]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('qr_invites', function (Blueprint $table) {
            $table->dropForeign(['class_id']);
            $table->dropIndex(['class_id']);
            $table->dropColumn('class_id');
        });
    }
};
