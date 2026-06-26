<?php

namespace App\Http\Controllers\Api;

use App\Contracts\StorageServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReplaceFileRequest;
use App\Http\Requests\UploadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class StorageController extends Controller
{
    public function __construct(
        private readonly StorageServiceInterface $storageService,
    ) {}

    public function upload(UploadRequest $request, string $bucket): JsonResponse
    {
        $file = $request->file('file');
        $path = $request->input('path');

        $url = match (true) {
            str_starts_with($file->getMimeType(), 'image/') => $this->storageService->uploadImage($file, $bucket, $path),
            default => $this->storageService->uploadDocument($file, $bucket, $path),
        };

        Log::info('File uploaded to storage', [
            'bucket' => $bucket,
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
        $url = $this->storageService->uploadImage($request->file('file'), 'profiles');

        return response()->json([
            'url' => $url,
            'message' => 'Profile image uploaded successfully.',
        ], 201);
    }

    public function uploadEventImage(UploadRequest $request): JsonResponse
    {
        $url = $this->storageService->uploadImage($request->file('file'), 'events');

        return response()->json([
            'url' => $url,
            'message' => 'Event image uploaded successfully.',
        ], 201);
    }

    public function uploadDocument(UploadRequest $request): JsonResponse
    {
        $bucket = $request->input('bucket', 'documents');
        $url = $this->storageService->uploadDocument($request->file('file'), $bucket);

        return response()->json([
            'url' => $url,
            'message' => 'Document uploaded successfully.',
        ], 201);
    }

    public function replaceFile(ReplaceFileRequest $request, string $bucket): JsonResponse
    {
        $url = $this->storageService->replaceFile(
            oldUrl: $request->input('old_url'),
            newFile: $request->file('file'),
            bucket: $bucket,
        );

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

        $deleted = $this->storageService->deleteFile($request->input('url'));

        if (!$deleted) {
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
