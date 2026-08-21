<?php

namespace App\Modules\Academic\Controllers;

use App\Modules\Academic\Models\Campaign;
use App\Modules\Academic\Models\CampaignHospital;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Services\EligibilityService;
use App\Modules\Admission\Models\Application;
use App\Modules\Admission\Models\Admission;
use App\Modules\Admission\Models\CapacityPool;
use App\Modules\Admission\Models\CapacityReservation;
use App\Modules\Institution\Models\Institution;
use App\Modules\Notification\Notifications\InstitutionNotification;
use App\Shared\Enums\InstitutionRole;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class D4ReservationController
{
    /** Retourne uniquement les campagnes ouvertes correspondant à la promotion active de l’étudiant connecté. */

    public function studentCampaigns(Request $request): JsonResponse
    {
        $student = Student::where('user_id', $request->user()->id)->firstOrFail();

        $promotionIds = $student->enrollments()->where('status', 'ACTIVE')->pluck('promotion_id');

        $items = Campaign::query()->where('status', 'OPEN')->where('university_id', $student->university_id)->where('ends_at', '>=', now())->whereHas('promotions', fn ($query) => $query->whereIn('promotions.id', $promotionIds))->with(['academicYear', 'promotions.level', 'media'])->orderBy('starts_at')->get();

        return response()->json([
            'data' => $items
        ]);
    }

    /** Liste les demandes D4 reçues par l’hôpital dans lequel l’utilisateur possède un rôle de gestion. */

    public function requests(Request $request): JsonResponse
    {
        $data = $request->validate(['hospital_id' => ['required', 'uuid']]);

        $hospital = Institution::where('public_id', $data['hospital_id'])->where('type', 'HOSPITAL')->firstOrFail();

        $this->hospitalAccess($request, $hospital->id);

        return response()->json([
            'data' => CampaignHospital::query()->where('hospital_id', $hospital->id)->whereNotNull('requested_at')->whereHas('campaign', fn ($query) => $query->where('strategy', 'D4_RESERVATION'))->with(['campaign.academicYear', 'campaign.promotions.level', 'campaign.media', 'media'])->latest('requested_at')->get()]);
    }

    /** Liste les étudiants ayant réservé une place D4 dans l’hôpital connecté. */
    public function hospitalReservations(Request $request): JsonResponse
    {
        $data = $request->validate(['hospital_id' => ['required', 'uuid'], 'status' => ['nullable', Rule::in(['RESERVED', 'ACCEPTED', 'CANCELLED'])]]);
        $hospital = Institution::where('public_id', $data['hospital_id'])->where('type', 'HOSPITAL')->firstOrFail();
        $this->hospitalAccess($request, $hospital->id);

        $query = Application::query()
            ->where('preferred_hospital_id', $hospital->id)
            ->whereHas('campaign', fn ($query) => $query->where('strategy', 'D4_RESERVATION'))
            ->with(['student.user.profile', 'student.university', 'campaign.academicYear', 'admission']);
        $query->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status));

        return response()->json(['data' => $query->latest('submitted_at')->paginate(min($request->integer('per_page', 25), 100))]);
    }

    /** Enregistre une seule réponse hospitalière et initialise le stock de places accepté. */

    public function respond(Request $request, CampaignHospital $campaignHospital): JsonResponse
    {
        $this->hospitalAccess($request, $campaignHospital->hospital_id);

        abort_unless($campaignHospital->campaign->strategy === 'D4_RESERVATION' && $campaignHospital->campaign->status === 'HOSPITAL_REQUESTS' && $campaignHospital->request_status === 'PENDING', 409, 'Cette demande ne peut plus recevoir de réponse.');

        $data = $request->validate(['decision' => ['required', Rule::in(['ACCEPTED', 'DECLINED'])], 'capacity' => ['nullable', 'integer', 'min:1', 'max:10000', Rule::requiredIf($request->input('decision') === 'ACCEPTED')], 'note' => ['nullable', 'string', 'max:3000']]);

        DB::transaction(function () use ($campaignHospital, $data): void {

            $capacity = $data['decision'] === 'ACCEPTED' ? $data['capacity'] : 0;

            $campaignHospital->update(['request_status' => $data['decision'], 'capacity' => $capacity, 'responded_at' => now(), 'response_note' => $data['note'] ?? null]);

            if ($data['decision'] === 'ACCEPTED') CapacityPool::updateOrCreate(['campaign_hospital_id' => $campaignHospital->id, 'level_id' => null], ['total_places' => $capacity, 'reserved_places' => 0]);
        });
        $university = $campaignHospital->campaign->university;

        Notification::send($university->members()->get(), new InstitutionNotification($university->public_id, $university->name, 'Réponse à une demande D4', $campaignHospital->hospital->name.' a répondu à la demande de réservation.', 'CAMPAIGN_REQUEST', 'INFO', '/university/campaigns/'.$campaignHospital->campaign->public_id));

        return response()->json(['data' => $campaignHospital->fresh()->load(['hospital', 'capacityPools'])]);
    }

    public function studentView(Request $request, Campaign $campaign, EligibilityService $eligibility): JsonResponse
    {
        $student = Student::where('user_id', $request->user()->id)->firstOrFail();
        
        abort_unless($eligibility->isEligible($student, $campaign), 403);
        
        $campaign->load(['academicYear', 'promotions.level', 'media', 'hospitals' => fn ($query) => $query->where('request_status', 'ACCEPTED')->with(['hospital.addresses', 'hospital.contacts', 'capacityPools'])]);
        
        $reservation = Application::query()->where('campaign_id', $campaign->id)->where('student_id', $student->id)->with('admission')->first();
        
        $hospitals = $campaign->hospitals->map(function ($item): array {
            $pool = $item->capacityPools->first();
            $address = $item->hospital->addresses->firstWhere('is_primary', true) ?? $item->hospital->addresses->first();
            return ['id' => $item->id, 'hospital' => [
                'id' => $item->hospital->public_id,
                'name' => $item->hospital->name,
                'short_name' => $item->hospital->short_name,
                'description' => $item->hospital->description,
                'website' => $item->hospital->website,
                'address' => $address ? [
                    'label' => $address->label,
                    'address_line' => $address->address_line,
                    'commune' => $address->commune,
                    'city' => $address->city,
                    'province' => $address->province,
                    'country' => $address->country,
                    'latitude' => $address->latitude !== null ? (float) $address->latitude : null,
                    'longitude' => $address->longitude !== null ? (float) $address->longitude : null,
                ] : null,
                'contacts' => $item->hospital->contacts->map(fn ($contact) => [
                    'type' => $contact->type, 'value' => $contact->value, 'label' => $contact->label,
                ])->values(),
            ], 'capacity' => $pool?->total_places ?? 0, 'reserved' => $pool?->reserved_places ?? 0, 'remaining' => max(0, ($pool?->total_places ?? 0) - ($pool?->reserved_places ?? 0))];
        });

        return response()->json(['data' => [...$campaign->toArray(), 'hospitals' => $hospitals, 'reservation' => $reservation]]);
    }

    /**
     * Réserve une place sous verrou transactionnel.
     *
     * Le verrou empêche deux étudiants de prendre simultanément la dernière
     * place disponible dans le même hôpital.
     */
    public function reserve(Request $request, Campaign $campaign, EligibilityService $eligibility): JsonResponse
    {
        $data = $request->validate(['hospital_id' => ['required', 'uuid']]);

        $student = Student::where('user_id', $request->user()->id)->firstOrFail();

        abort_unless($campaign->strategy === 'D4_RESERVATION' && $campaign->status === 'OPEN' && $eligibility->isEligible($student, $campaign), 422, 'Cette campagne n’est pas disponible pour cet étudiant.');

        $hospitalId = Institution::where('public_id', $data['hospital_id'])->where('type', 'HOSPITAL')->value('id');

        $application = DB::transaction(function () use ($campaign, $student, $hospitalId): Application {

            $application = Application::query()->where('campaign_id', $campaign->id)->where('student_id', $student->id)->lockForUpdate()->first();

            if ($application && ! in_array($application->status, ['WITHDRAWN', 'CANCELLED'], true)) throw ValidationException::withMessages([
                'reservation' => 'Une réservation active existe déjà pour cette campagne.'
            ]);

            $participation = CampaignHospital::query()->where('campaign_id', $campaign->id)->where('hospital_id', $hospitalId)->where('request_status', 'ACCEPTED')->lockForUpdate()->firstOrFail();
            
            $pool = CapacityPool::query()->where('campaign_hospital_id', $participation->id)->lockForUpdate()->firstOrFail();
            
            if ($pool->reserved_places >= $pool->total_places) throw ValidationException::withMessages([
                'hospital_id' => 'Cet hôpital ne dispose plus de place.']);
            
            $values = [
                'campaign_id' => $campaign->id,
                'student_id' => $student->id, 
                'preferred_hospital_id' => $hospitalId, 
                'status' => 'RESERVED',
                'submitted_at' => now(), 
                'reviewed_at' => null, 
                'reviewed_by' => null, 
                'review_note' => null
            ];
            
            $application ? $application->update($values) : $application = Application::create($values);
            
            $pool->increment('reserved_places');
            
            CapacityReservation::updateOrCreate([
                'application_id' => $application->id,
            ], [
                'capacity_pool_id' => $pool->id,
                'status' => 'HELD',
                'expires_at' => null
            ]);
            
            return $application;

        }, 3);

        return response()->json(['data' => $application], 201);
    }

    /** Confirme une réservation D4 et crée l’admission qui permettra d’organiser le stage. */
    public function admit(Request $request, Application $application): JsonResponse
    {
        abort_unless($application->preferred_hospital_id, 422, 'Cette réservation ne cible aucun hôpital.');
        $this->hospitalAccess($request, $application->preferred_hospital_id);
        abort_unless($application->campaign->strategy === 'D4_RESERVATION' && $application->status === 'RESERVED', 409, 'Cette réservation ne peut pas être admise.');

        $admission = DB::transaction(function () use ($application, $request): Admission {
            $reservation = CapacityReservation::query()->where('application_id', $application->id)->where('status', 'HELD')->lockForUpdate()->firstOrFail();
            $application->update(['status' => 'ACCEPTED', 'reviewed_at' => now(), 'reviewed_by' => $request->user()->id]);
            $reservation->update(['status' => 'CONFIRMED', 'expires_at' => null]);

            return Admission::firstOrCreate(
                ['application_id' => $application->id],
                ['student_id' => $application->student_id, 'hospital_id' => $application->preferred_hospital_id, 'status' => 'ACCEPTED', 'admitted_at' => now()],
            );
        });

        $application->load(['student.user', 'campaign', 'admission']);
        if ($application->student->user) {
            $hospital = Institution::findOrFail($application->preferred_hospital_id);
            $application->student->user->notify(new InstitutionNotification(
                $hospital->public_id,
                $hospital->name,
                'Réservation D4 confirmée',
                'Votre réservation a été confirmée par '.$hospital->name.'.',
                'ADMISSION',
                'INFO',
                '/student/campaigns/'.$application->campaign->public_id,
            ));
        }

        return response()->json(['data' => $admission->load(['application.student.user', 'student'])], 201);
    }

    /** Annule la réservation de l’étudiant et remet immédiatement la place dans le stock disponible. */

    public function cancel(Request $request, Application $application): JsonResponse
    {
        abort_unless($application->student()->where('user_id', $request->user()->id)->exists() && $application->status === 'RESERVED', 403);

        DB::transaction(function () use ($application): void {

            $reservation = CapacityReservation::query()->where('application_id', $application->id)->where('status', 'HELD')->lockForUpdate()->firstOrFail();

            $pool = CapacityPool::query()->lockForUpdate()->findOrFail($reservation->capacity_pool_id);

            if ($pool->reserved_places > 0) $pool->decrement('reserved_places');

            $reservation->update(['status' => 'CANCELLED']);

            $application->update(['status' => 'CANCELLED']);

        }, 3);

        return response()->json([
            'data' => $application->fresh()
        ]);
    }

    private function hospitalAccess(Request $request, int $hospitalId): void
    {
        $access = app(InstitutionAccess::class);

        abort_if($access->isSuperAdmin($request->user()), 403);

        abort_unless($access->has($request->user(), $hospitalId, [InstitutionRole::Admin->value, InstitutionRole::HospitalManager->value]), 403);
    }
}
