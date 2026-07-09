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
    public function __construct(
        private readonly StorageServiceInterface $storageService,
        private readonly FileUploadServiceInterface $fileUploadService,
    ) {}

    public function upload(UploadRequest $request, string $bucket): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');
        /** @var string|null $path */
        $path = $request->input('path');

        $mimeType = $file->getMimeType();
        $key = match (true) {
            is_string($mimeType) && str_starts_with($mimeType, 'image/') => $this->storageService->uploadImage($file, $bucket, $path),
            default => $this->storageService->uploadDocument($file, $bucket, $path),
        };

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
        /** @var string $bucket */
        $bucket = $request->input('bucket', 'documents');
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $key = $this->storageService->uploadDocument($file, $bucket);
        $url = $this->fileUploadService->url($key);

        return response()->json([
            'url' => $url,
            'message' => 'Document uploaded successfully.',
        ], 201);
    }

    public function replaceFile(ReplaceFileRequest $request, string $bucket): JsonResponse
    {
        /** @var string $oldUrl */
        $oldUrl = $request->input('old_url');
        /** @var UploadedFile $newFile */
        $newFile = $request->file('file');
        $key = $this->storageService->replaceFile(
            oldUrl: $oldUrl,
            newFile: $newFile,
            bucket: $bucket,
        );
        $url = $this->fileUploadService->url($key);

        Log::info('File replaced in storage', [
            'bucket' => $bucket,
            'old_url' => $request->input('old_url'),
            'new_url' => $url,
        ]);

        return response()->json([
            'url' => $url,
            'message' => 'File replaced successfully.',
        ]);
    }

    public function delete(string $bucket): JsonResponse
    {
        $request = request();
        $request->validate([
            'url' => ['required', 'string'],
        ]);

        /** @var string $fileUrl */
        $fileUrl = $request->input('url');
        $deleted = $this->storageService->deleteFile($fileUrl);

        if (! $deleted) {
            return response()->json([
                'message' => 'File not found or could not be deleted.',
            ], 404);
        }

        Log::info('File deleted from storage', [
            'bucket' => $bucket,
            'url' => $request->input('url'),
        ]);

        return response()->json([
            'message' => 'File deleted successfully.',
        ]);
    }
}
