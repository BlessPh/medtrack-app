<?php

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Resources\UserResource;
use App\Shared\Enums\UserStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

class AuthController
{
    public function login(LoginRequest $request): JsonResponse
    {
        /** @var JWTGuard $guard */
        $guard = auth('api');
        $rawIdentifier = trim($request->string('email')->toString());
        $identifier = mb_strtolower($rawIdentifier);
        $user = User::query()
            ->whereRaw('LOWER(COALESCE(email, ?)) = ?', ['', $identifier])
            ->orWhereRaw('LOWER(COALESCE(username, ?)) = ?', ['', $identifier])
            ->orWhere('phone', $rawIdentifier)
            ->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            return response()->json(['message' => 'Identifiants invalides.'], 422);
        }

        if ($user->status !== UserStatus::Active) {
            return response()->json(['message' => 'Ce compte n’est pas actif.'], 403);
        }

        $token = $guard->login($user);

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->tokenResponse($token, $user->load('profile'));
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('profile'));
    }

    public function logout(): JsonResponse
    {
        auth('api')->logout();

        return response()->json(null, 204);
    }

    public function refresh(Request $request): JsonResponse
    {
        /** @var JWTGuard $guard */
        $guard = auth('api');

        return $this->tokenResponse($guard->refresh(), $request->user()->load('profile'));
    }

    private function tokenResponse(string $token, User $user): JsonResponse
    {
        return response()->json(['data' => [
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => new UserResource($user),
        ]]);
    }
}
