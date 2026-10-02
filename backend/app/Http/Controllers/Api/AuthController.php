<?php

namespace App\Http\Controllers\Api;

use App\Contracts\AuthServiceInterface;
use App\Contracts\EmailVerificationServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthServiceInterface $authService,
        private readonly EmailVerificationServiceInterface $emailVerificationService,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());

        /** @var string|null $applicationStatus */
        $applicationStatus = $result['application_status'] ?? null;

        return response()->json([
            'message' => __('auth.login_success'),
            'data' => [
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
                'token_type' => $result['token_type'],
                'application_status' => $applicationStatus,
                'rejection_reason' => $result['rejection_reason'] ?? null,
                'access_state' => in_array($applicationStatus, ['pending', 'rejected'], true) ? 'restricted' : 'full',
            ],
        ]);
    }

    public function platformLogin(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->platformLogin($request->validated());

        return response()->json([
            'message' => 'Platform admin login successful.',
            'data' => [
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
                'token_type' => $result['token_type'],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authService->logout($user);

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        return response()->json([
            'message' => $result['message'],
            'data' => [
                'user' => new UserResource($result['user']),
            ],
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $this->authService->getAuthenticatedUser($user);

        return response()->json([
            'data' => [
                'user' => new UserResource($result['user']),
            ],
        ]);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'email' => ['required', 'email'],
        ]);

        /** @var string $email */
        $email = $request->input('email');
        /** @var string $rawToken */
        $rawToken = $request->input('token');

        $outcome = $this->emailVerificationService->verify($email, $rawToken);

        if (! $outcome->isVerified()) {
            return $this->verificationFailed();
        }

        return response()->json([
            'success' => true,
            'message' => __('auth.verification_succeeded'),
            'code' => 'VERIFIED',
        ]);
    }

    /**
     * The single, indistinguishable failure response.
     *
     * Unknown address, wrong token and already-verified account must all be
     * reported identically, otherwise this endpoint becomes an account
     * enumeration oracle.
     */
    private function verificationFailed(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => __('auth.verification_failed'),
            'code' => 'VERIFICATION_FAILED',
        ], 400);
    }

    /**
     * Resend a verification link to an arbitrary address.
     *
     * Always answers with the same body and status, whatever happened, so the
     * endpoint cannot be used to discover which addresses have accounts.
     */
    public function resendVerification(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        /** @var string $email */
        $email = $request->input('email');

        // Any dispatch outcome (unknown address, already verified, refused
        // transport, transport failure) is intentionally discarded: the response
        // must not reflect it.
        $this->emailVerificationService->resendForEmail($email);

        return response()->json([
            'success' => true,
            'message' => __('auth.verification_resent'),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        /** @var array<string, mixed> $data */
        $data = $request->only('email');
        $result = $this->authService->forgotPassword($data);

        return response()->json([
            'message' => $result['message'],
        ]);
    }
}
