<?php

namespace App\Services;

use App\Contracts\StorageServiceInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SupabaseStorageService implements StorageServiceInterface
{
    private string $projectUrl;
    private string $serviceRoleKey;
    private string $baseUrl;

    protected array $allowedImageMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    protected array $allowedDocumentMimes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/jpeg',
        'image/png',
    ];

    protected int $maxImageSize;

    protected int $maxDocumentSize;

    public function __construct()
    {
        $this->projectUrl = rtrim(config('supabase-storage.project_url', ''), '/');
        $this->serviceRoleKey = config('supabase-storage.service_role_key', '');
        $this->baseUrl = rtrim(config('supabase-storage.base_url', ''), '/');
        $this->maxImageSize = (int) config('supabase-storage.max_image_size', 5120);
        $this->maxDocumentSize = (int) config('supabase-storage.max_document_size', 10240);
    }

    public function uploadImage(UploadedFile $file, string $bucket, ?string $path = null): string
    {
        $this->validateImage($file);

        $key = $this->generateKey($file, $bucket, $path);

        $this->uploadRaw($key, $file);

        return $this->generatePublicUrl($key, $bucket);
    }

    public function uploadDocument(UploadedFile $file, string $bucket, ?string $path = null): string
    {
        $this->validateDocument($file);

        $key = $this->generateKey($file, $bucket, $path);

        $this->uploadRaw($key, $file);

        return $this->generatePublicUrl($key, $bucket);
    }

    public function deleteFile(string $url): bool
    {
        if (empty($url)) {
            return false;
        }

        try {
            $key = $this->extractKeyFromUrl($url);

            if (!$key) {
                Log::warning('Could not extract storage key from URL', ['url' => $url]);
                return false;
            }

            $bucket = $this->extractBucketFromKey($key);
            $objectPath = $this->extractObjectPathFromKey($key);

            $response = Http::withHeaders($this->authHeaders())
                ->timeout(30)
                ->delete("{$this->storageApiUrl()}/object/{$bucket}/{$objectPath}");

            if ($response->successful()) {
                Log::info('File deleted from Supabase Storage', [
                    'bucket' => $bucket,
                    'key' => $key,
                ]);
                return true;
            }

            if ($response->status() === 404) {
                Log::warning('File not found in Supabase Storage during delete', [
                    'bucket' => $bucket,
                    'key' => $key,
                ]);
                return false;
            }

            Log::warning('Failed to delete file from Supabase Storage', [
                'bucket' => $bucket,
                'key' => $key,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (ConnectionException $e) {
            Log::error('Network error deleting file from Supabase Storage', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return false;
        } catch (\Exception $e) {
            Log::warning('Failed to delete file from storage', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function replaceFile(string $oldUrl, UploadedFile $newFile, string $bucket, ?string $path = null): string
    {
        $this->deleteFile($oldUrl);

        return $this->uploadImage($newFile, $bucket, $path);
    }

    public function generatePublicUrl(string $key, string $bucket): string
    {
        if ($this->baseUrl) {
            return $this->baseUrl . '/' . $bucket . '/' . ltrim($key, '/');
        }

        return "{$this->projectUrl}/storage/v1/object/public/{$bucket}/{$this->stripBucketFromKey($key, $bucket)}";
    }

    public function getBucketUrl(string $bucket): string
    {
        if ($this->baseUrl) {
            return $this->baseUrl . '/' . $bucket;
        }

        return "{$this->projectUrl}/storage/v1/object/public/{$bucket}";
    }

    public function fileExists(string $key, string $bucket): bool
    {
        try {
            $objectPath = $this->stripBucketFromKey($key, $bucket);

            $response = Http::withHeaders($this->authHeaders())
                ->timeout(15)
                ->head("{$this->storageApiUrl()}/object/{$bucket}/{$objectPath}");

            return $response->successful();
        } catch (\Exception $e) {
            Log::warning('Error checking file existence in Supabase Storage', [
                'bucket' => $bucket,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getFileMetadata(string $key, string $bucket): ?array
    {
        try {
            $objectPath = $this->stripBucketFromKey($key, $bucket);

            $response = Http::withHeaders($this->authHeaders())
                ->timeout(15)
                ->get("{$this->storageApiUrl()}/object/{$bucket}/{$objectPath}/info");

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('Error getting file metadata from Supabase Storage', [
                'bucket' => $bucket,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function createBucket(string $name, bool $public = true): bool
    {
        try {
            $checkResponse = Http::withHeaders($this->authHeaders())
                ->timeout(15)
                ->get("{$this->storageApiUrl()}/bucket/{$name}");

            if ($checkResponse->successful()) {
                return true;
            }

            $response = Http::withHeaders($this->authHeaders())
                ->timeout(30)
                ->post("{$this->storageApiUrl()}/bucket", [
                    'name' => $name,
                    'id' => $name,
                    'public' => $public,
                ]);

            if ($response->successful()) {
                Log::info('Supabase storage bucket created', [
                    'bucket' => $name,
                    'public' => $public,
                ]);
                return true;
            }

            Log::error('Failed to create Supabase storage bucket', [
                'bucket' => $name,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Error creating Supabase storage bucket', [
                'bucket' => $name,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function deleteBucket(string $name): bool
    {
        try {
            $response = Http::withHeaders($this->authHeaders())
                ->timeout(30)
                ->delete("{$this->storageApiUrl()}/bucket/{$name}");

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Error deleting Supabase storage bucket', [
                'bucket' => $name,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    protected function uploadRaw(string $key, UploadedFile $file): void
    {
        $bucket = $this->extractBucketFromKey($key);
        $objectPath = $this->extractObjectPathFromKey($key);

        $response = Http::withHeaders(array_merge(
            $this->authHeaders(),
            ['Content-Type' => $file->getMimeType()]
        ))
            ->timeout(60)
            ->withBody(
                file_get_contents($file->getRealPath()),
                $file->getMimeType()
            )
            ->post("{$this->storageApiUrl()}/object/{$bucket}/{$objectPath}");

        if (!$response->successful()) {
            $status = $response->status();
            $body = $response->body();

            $message = match (true) {
                $status === 413 => 'File size exceeds Supabase Storage quota or limit.',
                $status === 403 => 'Permission denied. Check SUPABASE_SERVICE_ROLE_KEY.',
                $status === 404 => "Storage bucket '{$bucket}' does not exist. Run php artisan supabase:create-buckets.",
                $status === 507 => 'Supabase Storage quota exceeded on the free plan.',
                $status >= 500 => 'Supabase Storage server error. Try again later.',
                default => "Upload failed with status {$status}: {$body}",
            };

            Log::error('Supabase Storage upload failed', [
                'bucket' => $bucket,
                'key' => $key,
                'status' => $status,
                'body' => $body,
            ]);

            throw new \RuntimeException($message);
        }

        Log::info('File uploaded to Supabase Storage', [
            'bucket' => $bucket,
            'key' => $key,
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
        ]);
    }

    protected function generateKey(UploadedFile $file, string $bucket, ?string $path = null): string
    {
        $uuid = (string) Str::uuid();
        $extension = $file->getClientOriginalExtension();
        $filename = $uuid . '.' . $extension;

        if ($path) {
            return trim($bucket, '/') . '/' . trim($path, '/') . '/' . $filename;
        }

        return trim($bucket, '/') . '/' . $filename;
    }

    protected function extractKeyFromUrl(string $url): ?string
    {
        $prefixes = [];

        if ($this->baseUrl) {
            $prefixes[] = $this->baseUrl . '/';
        }

        $prefixes[] = $this->projectUrl . '/storage/v1/object/public/';

        foreach ($prefixes as $prefix) {
            if (str_starts_with($url, $prefix)) {
                return substr($url, strlen($prefix));
            }
        }

        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';

        $knownBuckets = ['profiles/', 'events/', 'documents/', 'ids/', 'attachments/'];
        foreach ($knownBuckets as $bp) {
            $pos = strpos($path, '/' . $bp);
            if ($pos !== false) {
                return substr($path, $pos + 1);
            }
            $pos = strpos($path, $bp);
            if ($pos !== false) {
                return substr($path, $pos);
            }
        }

        $cleanPath = ltrim($path, '/');
        $prefixes = array_merge(
            ['storage/v1/object/public/', 'storage/v1/object/'],
            $knownBuckets
        );
        foreach ($prefixes as $prefix) {
            if (str_starts_with($cleanPath, $prefix)) {
                return substr($cleanPath, strlen($prefix));
            }
        }

        return $cleanPath ?: null;
    }

    protected function extractBucketFromKey(string $key): string
    {
        $parts = explode('/', $key);
        return $parts[0];
    }

    protected function extractObjectPathFromKey(string $key): string
    {
        $parts = explode('/', $key);
        array_shift($parts);
        return implode('/', $parts);
    }

    protected function stripBucketFromKey(string $key, string $bucket): string
    {
        if (str_starts_with($key, $bucket . '/')) {
            return substr($key, strlen($bucket) + 1);
        }

        return $key;
    }

    protected function storageApiUrl(): string
    {
        return "{$this->projectUrl}/storage/v1";
    }

    protected function authHeaders(): array
    {
        return [
            'Authorization' => "Bearer {$this->serviceRoleKey}",
            'apikey' => $this->serviceRoleKey,
        ];
    }

    protected function validateImage(UploadedFile $file): void
    {
        if (!in_array($file->getMimeType(), $this->allowedImageMimes)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid image type. Allowed: %s. Got: %s.',
                    implode(', ', $this->allowedImageMimes),
                    $file->getMimeType()
                )
            );
        }

        $maxBytes = $this->maxImageSize * 1024;
        if ($file->getSize() > $maxBytes) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Image size must not exceed %d KB. Got %d KB.',
                    $this->maxImageSize,
                    round($file->getSize() / 1024)
                )
            );
        }

        $dimensions = @getimagesize($file->getRealPath());
        if ($dimensions) {
            [$width, $height] = $dimensions;
            if ($width > 4096 || $height > 4096) {
                throw new \InvalidArgumentException(
                    'Image dimensions must not exceed 4096x4096 pixels.'
                );
            }
        }
    }

    protected function validateDocument(UploadedFile $file): void
    {
        if (!in_array($file->getMimeType(), $this->allowedDocumentMimes)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid document type. Allowed: %s. Got: %s.',
                    implode(', ', $this->allowedDocumentMimes),
                    $file->getMimeType()
                )
            );
        }

        $maxBytes = $this->maxDocumentSize * 1024;
        if ($file->getSize() > $maxBytes) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Document size must not exceed %d KB. Got %d KB.',
                    $this->maxDocumentSize,
                    round($file->getSize() / 1024)
                )
            );
        }
    }
}
