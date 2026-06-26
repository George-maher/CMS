<?php

namespace App\Contracts;

use Illuminate\Http\UploadedFile;

interface StorageServiceInterface
{
    public function uploadImage(UploadedFile $file, string $bucket, ?string $path = null): string;

    public function uploadDocument(UploadedFile $file, string $bucket, ?string $path = null): string;

    public function deleteFile(string $url): bool;

    public function replaceFile(string $oldUrl, UploadedFile $newFile, string $bucket, ?string $path = null): string;

    public function generatePublicUrl(string $key, string $bucket): string;

    public function getBucketUrl(string $bucket): string;

    public function fileExists(string $key, string $bucket): bool;

    public function getFileMetadata(string $key, string $bucket): ?array;
}
