<?php

namespace App\Modules\Media\Controllers;

use App\Modules\Institution\Models\Institution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InstitutionLogoController
{
    public function show(Request $request, Institution $institution): StreamedResponse
    {
        abort_unless($request->user()->can('view', $institution), 403);
        $logo = $institution->logo()->firstOrFail();
        abort_unless(Storage::disk($logo->disk)->exists($logo->path), 404);

        return Storage::disk($logo->disk)->response($logo->path, $logo->original_name, [
            'Content-Type' => $logo->mime_type,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, Institution $institution): JsonResponse
    {
        abort_unless($request->user()->can('update', $institution), 403);
        $data = $request->validate(['logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=2400,max_height=2400']]);
        $file = $data['logo'];
        $extension = strtolower($file->guessExtension() ?: 'bin');
        $directory = 'institutions/'.$institution->public_id.'/logos/'.now()->format('Y/m');
        $path = $file->storeAs($directory, Str::uuid().'.'.$extension, 'local');
        abort_unless($path, 500, 'Le logo n’a pas pu être stocké.');
        $checksum = hash_file('sha256', $file->getRealPath());
        $oldPaths = [];

        try {
            $media = DB::transaction(function () use ($institution, $request, $file, $path, $checksum, &$oldPaths) {
                $oldPaths = $institution->media()->where('collection', 'LOGO')->get(['disk', 'path'])->all();
                $institution->media()->where('collection', 'LOGO')->delete();

                return $institution->media()->create([
                    'collection' => 'LOGO', 'disk' => 'local', 'path' => $path,
                    'display_name' => 'Logo de '.$institution->name,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize(), 'checksum' => $checksum,
                    'uploaded_by' => $request->user()->id,
                ]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        foreach ($oldPaths as $old) {
            Storage::disk($old->disk)->delete($old->path);
        }

        return response()->json(['message' => 'Logo mis à jour.', 'data' => [
            'id' => $media->public_id,
            'url' => route('api.v1.institutions.logo.show', $institution->public_id),
            'mime_type' => $media->mime_type, 'size' => $media->size,
        ]], 201);
    }

    public function destroy(Request $request, Institution $institution): JsonResponse
    {
        abort_unless($request->user()->can('update', $institution), 403);
        $logo = $institution->logo()->firstOrFail();
        $logo->delete();
        Storage::disk($logo->disk)->delete($logo->path);

        return response()->json(null, 204);
    }
}
