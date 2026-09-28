<?php

use App\Enums\UserRole;
use App\Models\Stage;
use App\Models\Classe;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $classYears = DB::table('class_years')->get();

        $grouped = $classYears->groupBy('church_id');

        foreach ($grouped as $churchId => $years) {
            if (!$churchId) continue;

            $stage = Stage::create([
                'church_id' => $churchId,
                'name' => 'Default Stage',
                'display_order' => 1,
            ]);

            foreach ($years as $i => $year) {
                $classe = Classe::create([
                    'church_id' => $churchId,
                    'stage_id' => $stage->id,
                    'name' => $year->name,
                    'description' => $year->description,
                    'display_order' => $i + 1,
                ]);

                User::withoutGlobalScopes()
                    ->where('class_year_id', $year->id)
                    ->where('church_id', $churchId)
                    ->update(['class_id' => $classe->id]);

                User::withoutGlobalScopes()
                    ->where('class_year_id', $year->id)
                    ->where('role' , UserRole::Servant)
                    ->where('church_id', $churchId)
                    ->each(fn(User $u) => $u->classes()->syncWithoutDetaching([$classe->id]));
            }
        }
    }

    public function down(): void
    {
        Stage::withoutGlobalScopes()->where('name', 'Default Stage')->delete();
    }
};
