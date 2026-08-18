<?php

namespace App\Modules\Media\Controllers;

use App\Modules\Academic\Models\Campaign;
use App\Modules\Academic\Models\CampaignHospital;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Policies\AcademicPolicy;
use App\Modules\Academic\Services\EligibilityService;
use App\Modules\Media\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignMediaController
{
    public function storeDocument(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->status === 'DRAFT' && app(AcademicPolicy::class)->manage($request->user(), $campaign->university_id), 403);
        return $this->store($request, $campaign, 'CAMPAIGN_DOCUMENT', 'campaigns/'.$campaign->public_id.'/documents');
    }

    public function storeCommonLetter(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->strategy === 'D4_RESERVATION' && $campaign->status === 'DRAFT' && app(AcademicPolicy::class)->manage($request->user(), $campaign->university_id), 403);
        return $this->store($request, $campaign, 'D4_COMMON_LETTER', 'campaigns/'.$campaign->public_id.'/letters/common');
    }

    public function storeHospitalLetter(Request $request, Campaign $campaign, CampaignHospital $campaignHospital): JsonResponse
    {
        abort_unless($campaignHospital->campaign_id === $campaign->id && $campaign->strategy === 'D4_RESERVATION' && $campaign->status === 'DRAFT' && app(AcademicPolicy::class)->manage($request->user(), $campaign->university_id), 403);
        return $this->store($request, $campaignHospital, 'D4_HOSPITAL_LETTER', 'campaigns/'.$campaign->public_id.'/letters/hospitals/'.$campaignHospital->public_id);
    }

    public function download(Request $request, Media $media, EligibilityService $eligibility): StreamedResponse
    {
        $owner = $media->mediable;
        $allowed = false;
        if ($owner instanceof Campaign) {
            $allowed = app(AcademicPolicy::class)->view($request->user(), $owner->university_id);
            if (! $allowed && $media->collection === 'CAMPAIGN_DOCUMENT') {
                $student = Student::where('user_id', $request->user()->id)->first();
                $allowed = $student && $eligibility->isEligible($student, $owner);
            }
            if (! $allowed && $media->collection === 'D4_COMMON_LETTER') $allowed = $owner->hospitals()->whereHas('hospital.members', fn ($query) => $query->whereKey($request->user()->id))->exists();
        } elseif ($owner instanceof CampaignHospital) {
            $allowed = app(AcademicPolicy::class)->view($request->user(), $owner->campaign->university_id) || $owner->hospital->members()->whereKey($request->user()->id)->exists();
        }
        abort_unless($allowed, 403);
        abort_unless(Storage::disk($media->disk)->exists($media->path), 404);
        return Storage::disk($media->disk)->download($media->path, $media->original_name, ['Content-Type' => $media->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Request $request, Media $media): JsonResponse
    {
        $campaign = $media->mediable instanceof Campaign ? $media->mediable : $media->mediable->campaign;
        abort_unless($campaign->status === 'DRAFT' && app(AcademicPolicy::class)->manage($request->user(), $campaign->university_id), 403);
        $disk = $media->disk; $path = $media->path; $media->delete(); Storage::disk($disk)->delete($path);
        return response()->json(status: 204);
    }

    private function store(Request $request, object $owner, string $collection, string $base): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:10240'], 'display_name' => ['required', 'string', 'max:200']]);
        $file = $data['file']; $extension = strtolower($file->guessExtension() ?: 'bin');
        $path = $file->storeAs($base.'/'.now()->format('Y/m'), Str::uuid().'.'.$extension, 'local');
        abort_unless($path, 500, 'Le document n’a pas pu être stocké.');
        $media = $owner->media()->create(['collection' => $collection, 'disk' => 'local', 'path' => $path, 'original_name' => $file->getClientOriginalName(), 'display_name' => trim($data['display_name']), 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size' => $file->getSize(), 'checksum' => hash_file('sha256', $file->getRealPath()), 'uploaded_by' => $request->user()->id]);
        return response()->json(['data' => $media, 'message' => 'Document ajouté.'], 201);
    }
}
