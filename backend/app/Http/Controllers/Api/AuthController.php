<?php

namespace App\Http\Controllers\Api;

use App\Contracts\AuthServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthServiceInterface $authService,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());

        return response()->json([
            'message' => 'Login successful.',
            'data' => [
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
                'token_type' => $result['token_type'],
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
        $this->authService->logout($request->user());

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
        $result = $this->authService->getAuthenticatedUser($request->user());

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

        $user = User::where('email', $request->input('email'))
            ->where('email_verification_token', $request->input('token'))
            ->first();

        if (!$user) {
            return response()->json(['message' => 'Invalid or expired verification link.'], 422);
        }

        if ($user->email_verified_at !== null) {
            return response()->json(['message' => 'Email is already verified. You can log in.'], 200);
        }

        $user->update([
            'email_verified_at' => now(),
            'email_verification_token' => null,
        ]);

        return response()->json([
            'message' => 'Email verified successfully. You can now log in.',
        ]);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->input('email'))->first();

        if (!$user) {
            return response()->json([
                'message' => 'If that email exists in our system, a verification email has been sent.',
            ]);
        }

        if ($user->email_verified_at !== null) {
            return response()->json([
                'message' => 'If that email exists in our system, a verification email has been sent.',
            ]);
        }

        $user->email_verification_token = Str::random(64);
        $user->save();

        $verificationUrl = config('app.frontend_url') . '/verify-email?token=' . urlencode($user->email_verification_token) . '&email=' . urlencode($user->email);

        try {
            $user->notify(new VerifyEmailNotification($user, $verificationUrl));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to resend verification email', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'If that email exists in our system, a verification email has been sent.',
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $result = $this->authService->forgotPassword($request->only('email'));

        return response()->json([
            'message' => 'If an account exists, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $result = $this->authService->resetPassword($request->only(
            'email', 'password', 'password_confirmation', 'token'
        ));

        return response()->json([
            'message' => $result['message'],
        ]);
    }
}
