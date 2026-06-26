<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChurchApplicationRequest;
use App\Http\Resources\ChurchApplicationResource;
use App\Services\ChurchApplicationService;
use Illuminate\Http\JsonResponse;

class ChurchApplicationController extends Controller
{
    public function __construct(
        private readonly ChurchApplicationService $churchApplicationService,
    ) {}

    public function store(ChurchApplicationRequest $request): JsonResponse
    {
        $result = $this->churchApplicationService->submit(
            $request->safe()->except(['front_id', 'back_id', 'church_permission_doc', 'password', 'password_confirmation']),
            $request->file('front_id'),
            $request->file('back_id'),
            $request->input('email'),
            $request->input('password'),
            $request->file('church_permission_doc'),
        );

        return response()->json([
            'message' => 'Application submitted successfully. You can now login to track your application status.',
            'data' => new ChurchApplicationResource($result['application']),
            'user' => [
                'id' => $result['user']->id,
                'email' => $result['user']->email,
            ],
        ], 201);
    }
}
