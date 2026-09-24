<?php

namespace App\Http\Controllers\Api;

use App\Contracts\ChurchApplicationServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChurchApplicationRequest;
use App\Http\Resources\ChurchApplicationResource;
use App\Models\ChurchApplication;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

class ChurchApplicationController extends Controller
{
    public function __construct(
        private readonly ChurchApplicationServiceInterface $churchApplicationService,
    ) {}

    public function store(ChurchApplicationRequest $request): JsonResponse
    {
        /** @var UploadedFile|null $frontId */
        $frontId = $request->file('front_id');
        /** @var UploadedFile|null $backId */
        $backId = $request->file('back_id');
        /** @var UploadedFile|null $permissionDoc */
        $permissionDoc = $request->file('church_permission_doc');
        /** @var string $email */
        $email = $request->input('email');
        /** @var string $password */
        $password = $request->input('password', '');
        /** @var array<string, mixed> $safeData */
        $safeData = $request->safe()->except(['front_id', 'back_id', 'church_permission_doc', 'password', 'password_confirmation']);

        // Ownership proof for updates of an existing application: resolve the
        // applicant session via the sanctum guard on this public route
        // (null when the caller is anonymous).
        /** @var User|null $authUser */
        $authUser = Auth::guard('sanctum')->user();

        $result = $this->churchApplicationService->submit(
            $safeData,
            $frontId,
            $backId,
            $email,
            $password,
            $permissionDoc,
            $authUser?->id,
        );

        $statusCode = $result['is_update'] ? 200 : 201;
        $message = $result['is_update']
            ? 'Application updated successfully.'
            : 'Application submitted successfully. You can now login to track your application status.';

        /** @var ChurchApplication $application */
        $application = $result['application'];
        /** @var User $user */
        $user = $result['user'];

        return response()->json([
            'message' => $message,
            'data' => new ChurchApplicationResource($application),
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
            ],
            'is_update' => $result['is_update'],
        ], $statusCode);
    }

    public function lookup(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        /** @var string $email */
        $email = $request->input('email');
        $application = $this->churchApplicationService->findByEmail($email);

        if (! $application) {
            return response()->json(['data' => null]);
        }

        // PII minimization: the full resource (names, phones, address, ID
        // document URLs, reviewer notes) is only returned to the authenticated
        // applicant account. Anonymous callers get existence + status only.
        /** @var User|null $authUser */
        $authUser = Auth::guard('sanctum')->user();

        if ($authUser === null || $authUser->church_application_id !== $application->id) {
            return response()->json([
                'data' => [
                    'contact_email' => $application->contact_email,
                    'status' => $application->status,
                ],
                'message' => __('application.exists_sign_in'),
            ]);
        }

        return response()->json([
            'data' => new ChurchApplicationResource($application),
            'message' => 'We found an existing application associated with this email. Your previously submitted information has been loaded.',
        ]);
    }
}
