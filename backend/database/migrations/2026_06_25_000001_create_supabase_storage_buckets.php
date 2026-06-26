<?php

use App\Services\SupabaseStorageService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $buckets = config('supabase-storage.buckets', []);

        $projectUrl = config('supabase-storage.project_url');
        $serviceRoleKey = config('supabase-storage.service_role_key');

        if (!$projectUrl || !$serviceRoleKey) {
            Log::warning('Skipped Supabase bucket creation: SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY not set.');
            return;
        }

        try {
            $service = app(SupabaseStorageService::class);

            foreach ($buckets as $key => $config) {
                $name = $config['name'] ?? $key;
                $public = $config['public'] ?? true;

                if ($service->createBucket($name, $public)) {
                    Log::info("Supabase bucket '{$name}' ensured.", ['public' => $public]);
                } else {
                    Log::warning("Could not create Supabase bucket '{$name}'.");
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to create Supabase storage buckets via migration.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        $buckets = config('supabase-storage.buckets', []);
        $service = app(SupabaseStorageService::class);

        foreach (array_reverse($buckets) as $key => $config) {
            $name = $config['name'] ?? $key;
            try {
                $service->deleteBucket($name);
            } catch (\Exception $e) {
                Log::warning("Could not delete Supabase bucket '{$name}': " . $e->getMessage());
            }
        }
    }
};
