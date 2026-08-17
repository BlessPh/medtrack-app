<?php

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Requests\StoreAvatarRequest;
use App\Modules\Auth\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AvatarController
{
    public function store(StoreAvatarRequest $request): UserResource
    {
        $file = $request->file('avatar');
        $path = $file->storeAs(
            'avatars/'.$request->user()->public_id,
            Str::uuid().'.'.$file->guessExtension(),
            'local',
        );
        abort_unless($path, 500, 'Échec du stockage de la photo de profil.');

        $profile = $request->user()->profile()->firstOrCreate();
        $previousPath = $profile->avatar_url;

        try {
            $profile->update(['avatar_url' => $path]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        if ($previousPath && $previousPath !== $path) {
            Storage::disk('local')->delete($previousPath);
        }

        return new UserResource($request->user()->fresh()->load('profile'));
    }

    public function show(Request $request): StreamedResponse
    {
        $path = $request->user()->profile()->value('avatar_url');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $profile = $request->user()->profile()->first();
        if (! $profile?->avatar_url) {
            return response()->json(status: 204);
        }

        $path = $profile->avatar_url;
        $profile->update(['avatar_url' => null]);
        Storage::disk('local')->delete($path);

        return response()->json(status: 204);
    }
}
