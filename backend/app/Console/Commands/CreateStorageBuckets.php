<?php

namespace App\Console\Commands;

use App\Services\SupabaseStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CreateStorageBuckets extends Command
{
    protected $signature = 'supabase:create-buckets';

    protected $description = 'Create Supabase storage buckets defined in config/supabase-storage.php';

    public function handle(SupabaseStorageService $storageService): int
    {
        /** @var string $projectUrl */
        $projectUrl = config('supabase-storage.project_url');
        /** @var string $serviceRoleKey */
        $serviceRoleKey = config('supabase-storage.service_role_key');

        if ($projectUrl === '' || $projectUrl === null || $serviceRoleKey === '' || $serviceRoleKey === null) {
            $this->error('SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY must be set in .env');
            $this->warn('Set SUPABASE_URL to your Supabase project URL (e.g., https://xxx.supabase.co)');
            $this->warn('Set SUPABASE_SERVICE_ROLE_KEY to your Supabase service_role key (from Settings → API)');

            return self::FAILURE;
        }

        /** @var array<string, array<string, mixed>> $buckets */
        $buckets = config('supabase-storage.buckets', []);
        $apiUrl = rtrim($projectUrl, '/') . '/storage/v1/bucket';

        $created = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($buckets as $key => $bucketConfig) {
            /** @var string $bucketName */
            $bucketName = $bucketConfig['name'] ?? $key;
            $isPublic = (bool) ($bucketConfig['public'] ?? true);

            $this->line("Checking bucket: {$bucketName}...");

            $check = Http::withHeaders([
                'apikey' => $serviceRoleKey,
                'Authorization' => "Bearer {$serviceRoleKey}",
            ])->get("{$apiUrl}/{$bucketName}");

            if ($check->successful()) {
                /** @var array<string, mixed> $bucketData */
                $bucketData = $check->json();
                $currentlyPublic = (bool) ($bucketData['public'] ?? false);

                if ($currentlyPublic !== $isPublic) {
                    $update = Http::withHeaders([
                        'apikey' => $serviceRoleKey,
                        'Authorization' => "Bearer {$serviceRoleKey}",
                        'Content-Type' => 'application/json',
                    ])->put("{$apiUrl}/{$bucketName}", [
                        'public' => $isPublic,
                    ]);

                    if ($update->successful()) {
                        $this->info("  ↳ Bucket '{$bucketName}' updated to " . ($isPublic ? 'public' : 'private') . '.');
                        $created++;
                    } else {
                        $this->error("  ✗ Failed to update bucket '{$bucketName}': {$update->body()}");
                        Log::error('Failed to update Supabase storage bucket', [
                            'bucket' => $bucketName,
                            'response' => $update->body(),
                        ]);
                        $failed++;
                    }
                } else {
                    $this->warn("  ↳ Bucket '{$bucketName}' already exists (public=" . ($currentlyPublic ? 'true' : 'false') . '). Skipping.');
                    $skipped++;
                }
                continue;
            }

            $response = Http::withHeaders([
                'apikey' => $serviceRoleKey,
                'Authorization' => "Bearer {$serviceRoleKey}",
                'Content-Type' => 'application/json',
            ])->post($apiUrl, [
                'name' => $bucketName,
                'id' => $bucketName,
                'public' => $isPublic,
            ]);

            if ($response->successful()) {
                $this->info("  ✓ Bucket '{$bucketName}' created successfully.");
                $created++;
            } else {
                $this->error("  ✗ Failed to create bucket '{$bucketName}': {$response->body()}");
                Log::error('Failed to create Supabase storage bucket', [
                    'bucket' => $bucketName,
                    'response' => $response->body(),
                ]);
                $failed++;
            }
        }

        $this->newLine();
        $this->table(
            ['Status', 'Count'],
            [
                ['Created', $created],
                ['Skipped (already exist)', $skipped],
                ['Failed', $failed],
            ]
        );

        if ($failed > 0) {
            return self::FAILURE;
        }

        $this->info('Storage bucket setup complete.');

        return self::SUCCESS;
    }
}
