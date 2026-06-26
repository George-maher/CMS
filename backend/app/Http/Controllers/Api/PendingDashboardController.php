<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChurchApplicationResource;
use App\Models\ChurchApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PendingDashboardController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        $application = ChurchApplication::find($user->church_application_id);

        return response()->json([
            'data' => [
                'application_status' => $user->application_status,
                'application' => $application ? new ChurchApplicationResource($application) : null,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ],
        ]);
    }
}
