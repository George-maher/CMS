<?php

namespace App\Http\Controllers\Api;

use App\Contracts\ChurchApplicationServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChurchApplicationRequest;
use App\Http\Resources\ChurchApplicationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChurchApplicationController extends Controller
{
    public function __construct(
        private readonly ChurchApplicationServiceInterface $churchApplicationService,
    ) {}

    public function store(ChurchApplicationRequest $request): JsonResponse
    {
        $result = $this->churchApplicationService->submit(
            $request->safe()->except(['front_id', 'back_id', 'church_permission_doc', 'password', 'password_confirmation']),
            $request->file('front_id'),
            $request->file('back_id'),
            $request->input('email'),
            $request->input('password', ''),
            $request->file('church_permission_doc'),
        );

        $statusCode = $result['is_update'] ? 200 : 201;
        $message = $result['is_update']
            ? 'Application updated successfully.'
            : 'Application submitted successfully. You can now login to track your application status.';

        return response()->json([
            'message' => $message,
            'data' => new ChurchApplicationResource($result['application']),
            'user' => [
                'id' => $result['user']->id,
                'email' => $result['user']->email,
            ],
            'is_update' => $result['is_update'],
        ], $statusCode);
    }

    public function lookup(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $application = $this->churchApplicationService->findByEmail($request->input('email'));

        if (!$application) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => new ChurchApplicationResource($application),
            'message' => 'We found an existing application associated with this email. Your previously submitted information has been loaded.',
        ]);
    }
}
