<?php

namespace App\Http\Controllers\Api;

use App\Contracts\FileUploadServiceInterface;
use App\Contracts\StorageServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReplaceFileRequest;
use App\Http\Requests\UploadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class StorageController extends Controller
{
    /**
     * Buckets the application is allowed to write into. Restricts the generic
     * /storage/upload/{bucket} and /storage/upload-document endpoints so a
     * caller cannot create objects in arbitrary Supabase buckets.
     *
     * @var array<int, string>
     */
    private const ALLOWED_BUCKETS = ['profiles', 'events', 'documents', 'ids', 'attachments'];

    public function __construct(
        private readonly StorageServiceInterface $storageService,
        private readonly FileUploadServiceInterface $fileUploadService,
    ) {}

    public function upload(UploadRequest $request, string $bucket): JsonResponse
    {
        $bucket = $this->normalizeBucket($bucket);
        $forbidden = $this->guardBucket($bucket);
        if ($forbidden !== null) {
            return $forbidden;
        }

        /** @var UploadedFile $file */
        $file = $request->file('file');
        /** @var string|null $path */
        $path = $request->input('path');

        try {
            $mimeType = $file->getMimeType();
            $key = match (true) {
                is_string($mimeType) && str_starts_with($mimeType, 'image/') => $this->storageService->uploadImage($file, $bucket, $path),
                default => $this->storageService->uploadDocument($file, $bucket, $path),
            };
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => 'File validation failed.',
                'errors' => ['file' => [$e->getMessage()]],
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $url = $this->fileUploadService->url($key);

        Log::info('File uploaded to storage', [
            'bucket' => $bucket,
            'key' => $key,
            'url' => $url,
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
        ]);

        return response()->json([
            'url' => $url,
            'bucket' => $bucket,
            'message' => 'File uploaded successfully.',
        ], 201);
    }

    public function uploadProfileImage(UploadRequest $request): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $key = $this->storageService->uploadImage($file, 'profiles');
        $url = $this->fileUploadService->url($key);

        return response()->json([
            'url' => $url,
            'message' => 'Profile image uploaded successfully.',
        ], 201);
    }

    public function uploadEventImage(UploadRequest $request): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $key = $this->storageService->uploadImage($file, 'events');
        $url = $this->fileUploadService->url($key);

        return response()->json([
            'url' => $url,
            'message' => 'Event image uploaded successfully.',
        ], 201);
    }

    public function uploadDocument(UploadRequest $request): JsonResponse
    {
        /** @var string $bucketInput */
        $bucketInput = $request->input('bucket', 'documents');
        $bucket = $this->normalizeBucket($bucketInput);
        $forbidden = $this->guardBucket($bucket);
        if ($forbidden !== null) {
            return $forbidden;
        }

        /** @var UploadedFile $file */
        $file = $request->file('file');

        try {
            $key = $this->storageService->uploadDocument($file, $bucket);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => 'File validation failed.',
                'errors' => ['file' => [$e->getMessage()]],
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }
        $url = $this->fileUploadService->url($key);

        return response()->json([
            'url' => $url,
            'message' => 'Document uploaded successfully.',
        ], 201);
    }

    public function replaceFile(ReplaceFileRequest $request, string $bucket): JsonResponse
    {
        $bucket = $this->normalizeBucket($bucket);
        $forbidden = $this->guardBucket($bucket);
        if ($forbidden !== null) {
            return $forbidden;
        }

        /** @var string $oldUrl */
        $oldUrl = $request->input('old_url');
        /** @var UploadedFile $newFile */
        $newFile = $request->file('file');

        try {
            $key = $this->storageService->replaceFile(
                oldUrl: $oldUrl,
                newFile: $newFile,
                bucket: $bucket,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => 'File validation failed.',
                'errors' => ['file' => [$e->getMessage()]],
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }
        $url = $this->fileUploadService->url($key);

        Log::info('File replaced in storage', [
            'bucket' => $bucket,
            'new_url' => $url,
        ]);

        return response()->json([
            'url' => $url,
            'message' => 'File replaced successfully.',
        ]);
    }

    public function delete(string $bucket): JsonResponse
    {
        $bucket = $this->normalizeBucket($bucket);
        $forbidden = $this->guardBucket($bucket);
        if ($forbidden !== null) {
            return $forbidden;
        }

        $request = request();
        $request->validate([
            'url' => ['required', 'string'],
        ]);

        /** @var string $fileUrl */
        $fileUrl = $request->input('url');
        $deleted = $this->storageService->deleteFile($fileUrl, $bucket);

        if (! $deleted) {
            return response()->json([
                'message' => 'File not found or could not be deleted.',
            ], 404);
        }

        Log::info('File deleted from storage', [
            'bucket' => $bucket,
            'bucket' => $bucket,
        ]);

        return response()->json([
            'message' => 'File deleted successfully.',
        ]);
    }

    private function normalizeBucket(string $bucket): string
    {
        // The route wildcard does not match slashes, but plain-dot / ..-like
        // sequences could otherwise reach the object key path.
        return strtolower(trim($bucket, ". \t\n\r\0\x0B/"));
    }

    private function guardBucket(string $bucket): ?JsonResponse
    {
        if (! in_array($bucket, self::ALLOWED_BUCKETS, true)) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['bucket' => ['The selected bucket is invalid.']],
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        return null;
    }
}
