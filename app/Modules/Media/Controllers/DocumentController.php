<?php

namespace App\Modules\Media\Controllers;

use App\Modules\Academic\Models\Student;
use App\Modules\Internship\Models\Internship;
use App\Modules\Media\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController
{
    private const OWNERS = ['student' => Student::class, 'internship' => Internship::class];

    private const COLLECTIONS = ['identity' => 5120, 'proof' => 10240, 'evaluation' => 5120];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['owner_type' => ['required', 'in:student,internship'], 'owner_id' => ['required', 'uuid'], 'collection' => ['required', 'in:identity,proof,evaluation'], 'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:'.self::COLLECTIONS[$request->input('collection', 'identity')]]]);
        $owner = self::OWNERS[$data['owner_type']]::where('public_id', $data['owner_id'])->firstOrFail();
        abort_unless($this->canAttach($request, $owner), 403);
        $file = $request->file('file');
        $name = Str::uuid().'.'.$file->guessExtension();
        $directory = implode('/', [
            'documents', $data['owner_type'], $owner->public_id,
            $data['collection'], now()->format('Y/m'),
        ]);
        $path = $file->storeAs($directory, $name, 'local');
        abort_unless($path, 500, 'Échec du stockage.');
        $document = Document::create(['uploaded_by' => $request->user()->id, 'documentable_type' => $owner::class, 'documentable_id' => $owner->id, 'collection' => $data['collection'], 'disk' => 'local', 'path' => $path, 'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(), 'extension' => $file->guessExtension(), 'size_bytes' => $file->getSize()]);

        return response()->json(['data' => $document], 201);
    }

    public function download(Request $request, Document $document): StreamedResponse
    {
        abort_unless($request->user()->can('view', $document), 403);
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        return Storage::disk($document->disk)->download($document->path, $document->original_name, ['Content-Type' => $document->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Request $request, Document $document): JsonResponse
    {
        abort_unless($request->user()->can('delete', $document), 403);
        $document->delete();

        return response()->json(status: 204);
    }

    private function canAttach(Request $request, object $owner): bool
    {
        if ($owner instanceof Student) {
            return $owner->user_id === $request->user()->id || $request->user()->institutions()->whereKey($owner->university_id)->exists();
        }

        return $owner->student_id === Student::where('user_id', $request->user()->id)->value('id') || $request->user()->institutions()->whereKey($owner->hospital_id)->exists();
    }
}
