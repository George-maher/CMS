<?php

namespace App\Contracts;

use Illuminate\Http\UploadedFile;

interface FileUploadServiceInterface
{
    public function upload(UploadedFile $file, string $path, ?string $disk = null): string;

    public function delete(?string $path, ?string $disk = null): bool;

    public function url(string $path, ?string $disk = null): ?string;

    public function uploadProfileImage(UploadedFile $file): string;

    public function uploadEventImage(UploadedFile $file): string;

    public function uploadIdImage(UploadedFile $file, string $applicationId): string;

    public function uploadDocumentFile(UploadedFile $file, string $applicationId): string;

    public function publicDisk(): string;

    public function uploadsDisk(): string;
}
