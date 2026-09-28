<?php

namespace Database\Seeders;

use App\Models\AttendanceContext;
use App\Models\User;
use App\Enums\UserRole;
use Illuminate\Database\Seeder;

class AttendanceContextSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', UserRole::PlatformAdmin)->first();
        $adminId = $admin?->id;

        $contexts = [
            ['name' => 'Sunday School', 'name_ar' => 'مدارس الأحد', 'slug' => 'sunday-school', 'description' => 'Regular Sunday school sessions for all classes', 'is_active' => true],
            ['name' => 'Holiday', 'name_ar' => 'العطلة', 'slug' => 'holiday', 'description' => 'Holiday and vacation programs', 'is_active' => true],
            ['name' => 'Tasbeha', 'name_ar' => 'تسبحة', 'slug' => 'tasbeha', 'description' => 'Evening praise and prayer gatherings', 'is_active' => true],
            ['name' => 'Mass', 'name_ar' => 'قداس', 'slug' => 'mass', 'description' => 'Divine liturgy and masses', 'is_active' => true],
            ['name' => 'Trip', 'name_ar' => 'رحلة', 'slug' => 'trip', 'description' => 'Church-organized trips and excursions', 'is_active' => true],
            ['name' => 'Spiritual Day', 'name_ar' => 'يوم روحي', 'slug' => 'spiritual-day', 'description' => 'Spiritual retreats and special spiritual days', 'is_active' => true],
            ['name' => 'Prayer Meeting', 'name_ar' => 'اجتماع صلاة', 'slug' => 'prayer-meeting', 'description' => 'Regular prayer meetings', 'is_active' => true],
            ['name' => 'Special Event', 'name_ar' => 'فعالية خاصة', 'slug' => 'special-event', 'description' => 'Special events and celebrations', 'is_active' => true],
        ];

        foreach ($contexts as $context) {
            AttendanceContext::withoutGlobalScope(\App\Models\Scopes\ChurchScope::class)
                ->firstOrCreate(
                    ['slug' => $context['slug']],
                    array_merge($context, [
                        'created_by' => $adminId,
                        'church_id' => null, // Global default — available to all churches
                    ])
                );
        }

        $this->command?->info('Default attendance contexts seeded successfully.');
    }
}
