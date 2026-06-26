<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponseTrait
{
    protected function success(mixed $data = null, string $message = 'Success.', int $status = 200, array $meta = null): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== null) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $status);
    }

    protected function created(mixed $data = null, string $message = 'Created successfully.'): JsonResponse
    {
        return $this->success($data, $message, 201);
    }

    protected function error(string $message = 'Error occurred.', int $status = 400, mixed $errors = null, ?string $code = null): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        if ($code !== null) {
            $response['code'] = $code;
        }

        return response()->json($response, $status);
    }

    protected function notFound(string $message = 'Resource not found.', ?string $code = null): JsonResponse
    {
        return $this->error($message, 404, null, $code ?? 'NOT_FOUND');
    }

    protected function forbidden(string $message = 'Forbidden.', ?string $code = null): JsonResponse
    {
        return $this->error($message, 403, null, $code ?? 'FORBIDDEN');
    }

    protected function unauthorized(string $message = 'Unauthenticated.', ?string $code = null): JsonResponse
    {
        return $this->error($message, 401, null, $code ?? 'UNAUTHORIZED');
    }

    protected function validationError(string $message, mixed $errors = null): JsonResponse
    {
        return $this->error($message, 422, $errors, 'VALIDATION_ERROR');
    }

    protected function paginated(mixed $data, array $meta, string $message = 'Success.'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $meta,
        ]);
    }

    protected function respondWithResource(mixed $resource, string $message = 'Success.', int $status = 200, array $meta = null): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $resource,
        ];

        if ($meta !== null) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $status);
    }

    protected function respondWithCollection(mixed $collection, array $meta, string $message = 'Success.'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $collection,
            'meta' => $meta,
        ]);
    }
}
