<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChurchApplicationResource;
use App\Http\Resources\ChurchResource;
use App\Models\Church;
use App\Models\ChurchApplication;
use App\Models\User;
use App\Services\ChurchApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformController extends Controller
{
    public function __construct(
        private readonly ChurchApplicationService $churchApplicationService,
    ) {}

    public function dashboard(): JsonResponse
    {
        $pendingCount = ChurchApplication::where('status', 'pending')->count();
        $approvedCount = ChurchApplication::where('status', 'approved')->count();
        $rejectedCount = ChurchApplication::where('status', 'rejected')->count();
        $churchCount = Church::count();
        $totalUsers = User::whereNotNull('church_id')->count();
        $activeChurches = Church::where('is_active', true)->count();
        $suspendedChurches = Church::where('is_suspended', true)->count();

        $recentApplications = ChurchApplication::where('status', 'pending')
            ->latest()
            ->take(10)
            ->get()
            ->map(fn($app) => [
                'id' => $app->id,
                'church_name' => $app->church_name,
                'priest_name' => $app->priest_name,
                'created_at' => $app->created_at->toISOString(),
            ]);

        return response()->json([
            'data' => [
                'pending_applications' => $pendingCount,
                'approved_applications' => $approvedCount,
                'rejected_applications' => $rejectedCount,
                'total_churches' => $churchCount,
                'active_churches' => $activeChurches,
                'suspended_churches' => $suspendedChurches,
                'total_users' => $totalUsers,
                'recent_applications' => $recentApplications,
            ],
        ]);
    }

    public function applications(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $applications = $this->churchApplicationService->listApplications($status, (int) $request->query('per_page', 15));

        return response()->json([
            'data' => ChurchApplicationResource::collection($applications),
            'meta' => [
                'current_page' => $applications->currentPage(),
                'last_page' => $applications->lastPage(),
                'per_page' => $applications->perPage(),
                'total' => $applications->total(),
            ],
        ]);
    }

    public function showApplication(int $id): JsonResponse
    {
        $application = ChurchApplication::with('reviewer')->findOrFail($id);

        return response()->json([
            'data' => new ChurchApplicationResource($application),
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $application = ChurchApplication::findOrFail($id);

        if ($application->status !== 'pending') {
            return response()->json(['message' => 'Application is not pending.'], 422);
        }

        $church = $this->churchApplicationService->approve(
            $application,
            $request->user(),
            $request->input('notes'),
        );

        return response()->json([
            'message' => 'Application approved. Church admin account created.',
            'data' => [
                'application' => new ChurchApplicationResource($application->fresh()),
                'church' => new ChurchResource($church),
            ],
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate(['rejection_reason' => 'required|string|max:2000']);

        $application = ChurchApplication::findOrFail($id);

        if ($application->status !== 'pending') {
            return response()->json(['message' => 'Application is not pending.'], 422);
        }

        $this->churchApplicationService->reject(
            $application,
            $request->user(),
            $request->input('rejection_reason'),
        );

        return response()->json([
            'message' => 'Application rejected.',
            'data' => new ChurchApplicationResource($application->fresh()),
        ]);
    }

}
