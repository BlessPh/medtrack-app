<?php

namespace App\Modules\Institution\Controllers;

use App\Modules\Academic\Policies\AcademicPolicy;
use App\Modules\Institution\Models\Institution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InstitutionDetailsController
{
    public function storeUnit(Request $request, Institution $institution): JsonResponse
    {
        $this->authorizeUnit($request, $institution);
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer'],
            'type' => ['required', Rule::in([$this->unitType($institution)])],
            'code' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:200'],
        ]);
        if (isset($data['parent_id'])) {
            abort_unless($institution->units()->whereKey($data['parent_id'])->exists(), 422, 'Unité parente invalide.');
        }

        return $this->created($institution->units()->create($data + ['status' => 'ACTIVE']));
    }

    public function updateUnit(Request $request, Institution $institution, int $id): JsonResponse
    {
        $this->authorizeUnit($request, $institution);
        $unit = $institution->units()->whereKey($id)->firstOrFail();
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer'],
            'type' => ['required', Rule::in([$this->unitType($institution)])],
            'code' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:200'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);
        abort_if(($data['parent_id'] ?? null) === $unit->id, 422, 'Une unité ne peut pas être sa propre parente.');
        if (isset($data['parent_id'])) {
            abort_unless($institution->units()->whereKey($data['parent_id'])->exists(), 422, 'Unité parente invalide.');
        }
        $unit->update($data);

        return response()->json(['data' => $unit->fresh()]);
    }

    public function storeAddress(Request $request, Institution $institution): JsonResponse
    {
        $this->authorize($request, $institution);
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
            'address_line' => ['required', 'string', 'max:1000'],
            'commune' => ['nullable', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'size:2'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);
        $this->clearPrimary($institution, 'addresses', $data);

        return $this->created($institution->addresses()->create($data));
    }

    public function updateAddress(Request $request, Institution $institution, int $id): JsonResponse
    {
        $this->authorize($request, $institution);
        $address = $institution->addresses()->whereKey($id)->firstOrFail();
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
            'address_line' => ['required', 'string', 'max:1000'],
            'commune' => ['nullable', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'country' => ['required', 'string', 'size:2'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);
        $this->clearPrimary($institution, 'addresses', $data);
        $address->update($data);

        return response()->json(['data' => $address->fresh()]);
    }

    public function storeContact(Request $request, Institution $institution): JsonResponse
    {
        $this->authorize($request, $institution);
        $data = $request->validate([
            'type' => ['required', 'in:EMAIL,PHONE,WHATSAPP'],
            'value' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:100'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);
        $this->clearPrimary($institution, 'contacts', $data);

        return $this->created($institution->contacts()->create($data));
    }

    public function updateContact(Request $request, Institution $institution, int $id): JsonResponse
    {
        $this->authorize($request, $institution);
        $contact = $institution->contacts()->whereKey($id)->firstOrFail();
        $data = $request->validate([
            'type' => ['required', Rule::in(['EMAIL', 'PHONE', 'WHATSAPP'])],
            'value' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:100'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);
        $this->clearPrimary($institution, 'contacts', $data);
        $contact->update($data);

        return response()->json(['data' => $contact->fresh()]);
    }

    public function destroy(Request $request, Institution $institution, string $resource, int $id): JsonResponse
    {
        $resource === 'units' ? $this->authorizeUnit($request, $institution) : $this->authorize($request, $institution);
        abort_unless(in_array($resource, ['units', 'addresses', 'contacts'], true), 404);
        $item = $institution->{$resource}()->whereKey($id)->firstOrFail();
        if ($resource === 'units') {
            abort_if($item->children()->exists(), 422, 'Cette unité contient des sous-unités.');
        }
        $item->delete();

        return response()->json(status: 204);
    }

    private function authorize(Request $request, Institution $institution): void
    {
        abort_unless($request->user()->can('update', $institution), 403);
    }

    private function authorizeUnit(Request $request, Institution $institution): void
    {
        if ($institution->type === 'UNIVERSITY') {
            abort_unless(app(AcademicPolicy::class)->manage($request->user(), $institution->id), 403);
            return;
        }
        $this->authorize($request, $institution);
    }

    private function unitType(Institution $institution): string
    {
        return $institution->type === 'UNIVERSITY' ? 'DEPARTMENT' : 'SERVICE';
    }

    private function clearPrimary(Institution $institution, string $relation, array $data): void
    {
        if ($data['is_primary'] ?? false) {
            $institution->{$relation}()->where('is_primary', true)->update(['is_primary' => false]);
        }
    }

    private function created(Model $model): JsonResponse
    {
        return response()->json(['data' => $model], 201);
    }
}
