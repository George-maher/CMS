<?php

namespace App\Services;

use App\Contracts\StorageServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LocalStorageService implements StorageServiceInterface
{
    protected string $disk;

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
        $this->disk = config('filesystems.default') === 'local' ? 'public' : config('filesystems.default');
        $this->maxImageSize = (int) config('supabase-storage.max_image_size', 5120);
        $this->maxDocumentSize = (int) config('supabase-storage.max_document_size', 10240);
    }

    public function uploadImage(UploadedFile $file, string $bucket, ?string $path = null): string
    {
        $this->validateImage($file);
        $key = $this->generateKey($file, $bucket, $path);
        Storage::disk($this->disk)->putFileAs(
            dirname($key),
            $file,
            basename($key),
        );
        Log::info('Image uploaded to local storage', [
            'bucket' => $bucket,
            'key' => $key,
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
        ]);
        return $key;
    }

    public function uploadDocument(UploadedFile $file, string $bucket, ?string $path = null): string
    {
        $this->validateDocument($file);
        $key = $this->generateKey($file, $bucket, $path);
        Storage::disk($this->disk)->putFileAs(
            dirname($key),
            $file,
            basename($key),
        );
        Log::info('Document uploaded to local storage', [
            'bucket' => $bucket,
            'key' => $key,
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
        ]);
        return $key;
    }

    public function deleteFile(string $url): bool
    {
        if (empty($url)) {
            return false;
        }
        $key = $this->extractKeyFromUrl($url);
        if (!$key) {
            Log::warning('Could not extract storage key from URL', ['url' => $url]);
            return false;
        }
        try {
            if (Storage::disk($this->disk)->exists($key)) {
                Storage::disk($this->disk)->delete($key);
                Log::info('File deleted from local storage', ['key' => $key]);
                return true;
            }
            Log::warning('File not found in local storage during delete', ['key' => $key]);
            return false;
        } catch (\Exception $e) {
            Log::warning('Failed to delete file from local storage', [
                'key' => $key,
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
        return Storage::disk($this->disk)->url($key);
    }

    public function getBucketUrl(string $bucket): string
    {
        $baseUrl = rtrim(config('app.url', 'http://localhost'), '/');
        return "{$baseUrl}/storage/{$bucket}";
    }

    public function fileExists(string $key, string $bucket): bool
    {
        return Storage::disk($this->disk)->exists($key);
    }

    public function getFileMetadata(string $key, string $bucket): ?array
    {
        try {
            $path = Storage::disk($this->disk)->path($key);
            if (!file_exists($path)) {
                return null;
            }
            return [
                'name' => basename($key),
                'size' => filesize($path),
                'mime' => mime_content_type($path) ?: 'application/octet-stream',
                'last_modified' => filemtime($path),
            ];
        } catch (\Exception $e) {
            Log::warning('Error getting file metadata', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function generateKey(UploadedFile $file, string $bucket, ?string $path = null): string
    {
        $uuid = (string) Str::uuid();
        $extension = $file->getClientOriginalExtension();
        $filename = $uuid . '.' . $extension;
        $parts = [$bucket];
        if ($path) {
            $parts[] = trim($path, '/');
        }
        $parts[] = $filename;
        return implode('/', $parts);
    }

    protected function extractKeyFromUrl(string $url): ?string
    {
        $storagePrefix = '/storage/';
        $pos = strpos($url, $storagePrefix);
        if ($pos !== false) {
            return substr($url, $pos + strlen($storagePrefix));
        }
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';
        $clean = ltrim($path, '/');
        if (str_starts_with($clean, 'storage/')) {
            return substr($clean, strlen('storage/'));
        }
        return $clean ?: null;
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
