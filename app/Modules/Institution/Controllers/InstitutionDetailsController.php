<?php

namespace App\Modules\Institution\Controllers;

use App\Modules\Academic\Policies\AcademicPolicy;
use App\Modules\Institution\Models\Institution;
use App\Modules\Institution\Services\InstitutionAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InstitutionDetailsController
{
    public function storeUnit(Request $request, Institution $institution, InstitutionAudit $audit): JsonResponse
    {
        $this->authorizeUnit($request, $institution);
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer'],
            'type' => ['required', Rule::in($this->unitTypes($institution))],
            'code' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:200'],
        ]);
        $this->validateUnitHierarchy($institution, $data);

        $unit = $institution->units()->create($data + ['status' => 'ACTIVE']);
        $audit->record($request, $institution, 'UNIT_CREATED', 'unit', $unit->id, null, $unit->toArray());
        return $this->created($unit);
    }

    public function updateUnit(Request $request, Institution $institution, int $id, InstitutionAudit $audit): JsonResponse
    {
        $this->authorizeUnit($request, $institution);
        $unit = $institution->units()->whereKey($id)->firstOrFail();
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer'],
            'type' => ['required', Rule::in($this->unitTypes($institution))],
            'code' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:200'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);
        abort_if(($data['parent_id'] ?? null) === $unit->id, 422, 'Une unité ne peut pas être sa propre parente.');
        $this->validateUnitHierarchy($institution, $data, $unit->id);
        abort_if($unit->children()->exists() && $data['type'] !== $unit->type, 422, 'Une unité contenant des sous-unités ne peut pas changer de type.');
        $before = $unit->toArray();
        $unit->update($data);
        $audit->record($request, $institution, 'UNIT_UPDATED', 'unit', $unit->id, $before, $unit->fresh()->toArray());

        return response()->json(['data' => $unit->fresh()]);
    }

    public function storeAddress(Request $request, Institution $institution, InstitutionAudit $audit): JsonResponse
    {
        $this->authorize($request, $institution);
        $data = $request->validate($this->addressRules());
        if (! $institution->addresses()->exists()) {
            $data['is_primary'] = true;
        }
        $this->clearPrimary($institution, 'addresses', $data);

        $address = $institution->addresses()->create($data);
        $audit->record($request, $institution, 'ADDRESS_CREATED', 'address', $address->id, null, $address->toArray());
        return $this->created($address);
    }

    public function updateAddress(Request $request, Institution $institution, int $id, InstitutionAudit $audit): JsonResponse
    {
        $this->authorize($request, $institution);
        $address = $institution->addresses()->whereKey($id)->firstOrFail();
        $data = $request->validate($this->addressRules());
        if ($address->is_primary && ($data['is_primary'] ?? false) === false && ! $institution->addresses()->whereKeyNot($address->id)->where('is_primary', true)->exists()) {
            $data['is_primary'] = true;
        }
        $this->clearPrimary($institution, 'addresses', $data);
        $before = $address->toArray();
        $address->update($data);
        $audit->record($request, $institution, 'ADDRESS_UPDATED', 'address', $address->id, $before, $address->fresh()->toArray());

        return response()->json(['data' => $address->fresh()]);
    }

    public function storeContact(Request $request, Institution $institution, InstitutionAudit $audit): JsonResponse
    {
        $this->authorize($request, $institution);
        $data = $request->validate($this->contactRules($request));
        if (! $institution->contacts()->exists()) {
            $data['is_primary'] = true;
        }
        $this->clearPrimary($institution, 'contacts', $data);

        $contact = $institution->contacts()->create($data);
        $audit->record($request, $institution, 'CONTACT_CREATED', 'contact', $contact->id, null, $contact->toArray());
        return $this->created($contact);
    }

    public function updateContact(Request $request, Institution $institution, int $id, InstitutionAudit $audit): JsonResponse
    {
        $this->authorize($request, $institution);
        $contact = $institution->contacts()->whereKey($id)->firstOrFail();
        $data = $request->validate($this->contactRules($request));
        if ($contact->is_primary && ($data['is_primary'] ?? false) === false && ! $institution->contacts()->whereKeyNot($contact->id)->where('is_primary', true)->exists()) {
            $data['is_primary'] = true;
        }
        $this->clearPrimary($institution, 'contacts', $data);
        $before = $contact->toArray();
        $contact->update($data);
        $audit->record($request, $institution, 'CONTACT_UPDATED', 'contact', $contact->id, $before, $contact->fresh()->toArray());

        return response()->json(['data' => $contact->fresh()]);
    }

    public function destroy(Request $request, Institution $institution, string $resource, int $id, InstitutionAudit $audit): JsonResponse
    {
        $resource === 'units' ? $this->authorizeUnit($request, $institution) : $this->authorize($request, $institution);
        abort_unless(in_array($resource, ['units', 'addresses', 'contacts'], true), 404);
        $item = $institution->{$resource}()->whereKey($id)->firstOrFail();
        if ($resource === 'units') {
            abort_if($item->children()->exists(), 422, 'Cette unité contient des sous-unités.');
            abort_if(DB::table('rotations')->where('institution_unit_id', $item->id)->exists(), 422, 'Cette unité est déjà utilisée dans une rotation. Désactivez-la au lieu de la supprimer.');
        }
        $wasPrimaryAddress = $resource === 'addresses' && $item->is_primary;
        $wasPrimaryContact = $resource === 'contacts' && $item->is_primary;
        $before = $item->toArray();
        $item->delete();
        $audit->record($request, $institution, strtoupper(rtrim($resource, 's')).'_DELETED', rtrim($resource, 's'), $id, $before);
        if ($wasPrimaryAddress) {
            $institution->addresses()->oldest('id')->limit(1)->update(['is_primary' => true]);
        }
        if ($wasPrimaryContact) {
            $institution->contacts()->oldest('id')->limit(1)->update(['is_primary' => true]);
        }

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

    private function unitTypes(Institution $institution): array
    {
        return $institution->type === 'UNIVERSITY' ? ['DEPARTMENT'] : ['SERVICE', 'DEPARTMENT'];
    }

    private function validateUnitHierarchy(Institution $institution, array $data, ?int $currentId = null): void
    {
        $parent = isset($data['parent_id'])
            ? $institution->units()->whereKey($data['parent_id'])->first()
            : null;

        abort_if(isset($data['parent_id']) && ! $parent, 422, 'Unité parente invalide.');
        abort_if($currentId !== null && $parent?->id === $currentId, 422, 'Une unité ne peut pas être sa propre parente.');

        if ($institution->type !== 'HOSPITAL') {
            return;
        }

        if ($data['type'] === 'SERVICE') {
            abort_if($parent !== null, 422, 'Un service hospitalier doit être une unité racine.');
            return;
        }

        abort_unless($parent && $parent->type === 'SERVICE' && $parent->parent_id === null, 422, 'Un département hospitalier doit appartenir directement à un service.');
    }

    private function clearPrimary(Institution $institution, string $relation, array $data): void
    {
        if ($data['is_primary'] ?? false) {
            $institution->{$relation}()->where('is_primary', true)->update(['is_primary' => false]);
        }
    }

    private function addressRules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:100'],
            'address_line' => ['required', 'string', 'max:1000'],
            'commune' => ['nullable', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'country' => ['required', 'string', 'size:2'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    private function contactRules(Request $request): array
    {
        $valueRules = ['required', 'string', 'max:255'];
        if ($request->input('type') === 'EMAIL') {
            $valueRules[] = 'email:rfc';
        } elseif (in_array($request->input('type'), ['PHONE', 'WHATSAPP'], true)) {
            $valueRules[] = 'regex:/^\+?[0-9][0-9\s().-]{6,24}$/';
        }

        return [
            'type' => ['required', Rule::in(['EMAIL', 'PHONE', 'WHATSAPP'])],
            'value' => $valueRules,
            'label' => ['nullable', 'string', 'max:100'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    private function created(Model $model): JsonResponse
    {
        return response()->json(['data' => $model], 201);
    }
}
