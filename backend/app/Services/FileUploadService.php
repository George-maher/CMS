<?php

namespace App\Services;

use App\Contracts\FileUploadServiceInterface;
use App\Contracts\StorageServiceInterface;
use Illuminate\Http\UploadedFile;

class FileUploadService implements FileUploadServiceInterface
{
    protected array $bucketMap = [
        'uploads/events' => 'events',
        'uploads/profiles' => 'profiles',
        'uploads/avatars' => 'profiles',
        'uploads/documents' => 'documents',
        'uploads/ids' => 'ids',
        'church-applications' => 'ids',
    ];

    public function __construct(
        private readonly StorageServiceInterface $storageService,
    ) {}

    public function upload(UploadedFile $file, string $path, ?string $disk = null): string
    {
        $bucket = $this->resolveBucket($path);

        if (str_starts_with($file->getMimeType(), 'image/')) {
            return $this->storageService->uploadImage($file, $bucket, $path);
        }

        return $this->storageService->uploadDocument($file, $bucket, $path);
    }

    public function delete(?string $path, ?string $disk = null): bool
    {
        return $this->storageService->deleteFile($path ?? '');
    }

    public function url(string $path, ?string $disk = null): ?string
    {
        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $bucket = $this->resolveBucket($path);

        return $this->storageService->generatePublicUrl($path, $bucket);
    }

    public function publicDisk(): string
    {
        return 'supabase';
    }

    public function uploadsDisk(): string
    {
        return 'supabase';
    }

    public function uploadProfileImage(UploadedFile $file): string
    {
        return $this->storageService->uploadImage($file, 'profiles');
    }

    public function uploadEventImage(UploadedFile $file): string
    {
        return $this->storageService->uploadImage($file, 'events');
    }

    public function uploadIdImage(UploadedFile $file, string $applicationId): string
    {
        return $this->storageService->uploadImage($file, 'ids', "church-applications/{$applicationId}");
    }

    public function uploadDocumentFile(UploadedFile $file, string $applicationId): string
    {
        return $this->storageService->uploadDocument($file, 'documents', "church-applications/{$applicationId}");
    }

    protected function resolveBucket(string $path): string
    {
        foreach ($this->bucketMap as $prefix => $bucket) {
            if (str_starts_with($path, $prefix)) {
                return $bucket;
            }
        }

        if (str_starts_with($path, 'profiles') || str_starts_with($path, 'events') || str_starts_with($path, 'documents') || str_starts_with($path, 'ids')) {
            $parts = explode('/', $path);
            return $parts[0];
        }

        return 'attachments';
    }
}
