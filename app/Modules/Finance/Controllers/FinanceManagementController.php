<?php

namespace App\Modules\Finance\Controllers;

use App\Modules\Finance\Models\FinancialObligation;
use App\Modules\Finance\Models\PaymentRefund;
use App\Modules\Finance\Models\PaymentTransaction;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Institution\Models\Institution;
use App\Shared\Enums\InstitutionRole;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceManagementController
{
    public function contextData(Request $request): JsonResponse
    {
        $data = $request->validate(['institution_id' => ['required', 'uuid']]);
        $institution = $this->institution($request, $data['institution_id']);
        $students = \App\Modules\Academic\Models\Student::query()
            ->when(
                $institution->type === 'HOSPITAL',
                fn ($query) => $query->whereHas('admissions', fn ($admissions) => $admissions->where('hospital_id', $institution->id)),
                fn ($query) => $query->where('university_id', $institution->id),
            )
            ->with(['user.profile', 'university'])->orderBy('student_number')->get();
        $universityIds = $students->pluck('university_id')->unique();
        $campaigns = \App\Modules\Academic\Models\Campaign::query()
            ->when(
                $institution->type === 'HOSPITAL',
                fn ($query) => $query->whereIn('university_id', $universityIds)
                    ->whereHas('hospitals', fn ($hospitals) => $hospitals->where('hospital_id', $institution->id)->where('request_status', 'ACCEPTED')),
                fn ($query) => $query->where('university_id', $institution->id),
            )
            ->with('university')->latest('starts_at')->get(['id', 'public_id', 'university_id', 'name', 'status']);

        return response()->json(['data' => [
            'students' => $students->map(fn ($student) => ['id' => $student->public_id, 'name' => $student->user?->name ?? $student->student_number, 'student_number' => $student->student_number, 'university_id' => $student->university?->public_id, 'university' => $student->university?->name]),
            'universities' => $students->pluck('university')->filter()->unique('id')->values()->map(fn ($university) => ['id' => $university->public_id, 'name' => $university->name]),
            'campaigns' => $campaigns->map(fn ($campaign) => ['id' => $campaign->public_id, 'name' => $campaign->name, 'university_id' => $campaign->university?->public_id, 'status' => $campaign->status]),
        ]]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        [$institution, $filters] = $this->context($request);
        $obligations = $this->obligationsQuery($institution, $filters);
        $transactions = $this->transactionsQuery($institution, $filters);
        $refunds = $this->refundsQuery($institution, $filters);

        $byCurrency = (clone $obligations)->select('currency')
            ->selectRaw('SUM(amount) expected_amount')
            ->selectRaw('SUM(paid_amount) received_amount')
            ->selectRaw('SUM(amount - paid_amount) pending_amount')
            ->groupBy('currency')->get();

        $byUniversity = (clone $obligations)->join('students', 'students.id', '=', 'financial_obligations.student_id')
            ->join('institutions as universities', 'universities.id', '=', 'students.university_id')
            ->select('universities.public_id as university_id', 'universities.name as university', 'financial_obligations.currency')
            ->selectRaw('SUM(financial_obligations.amount) expected_amount')
            ->selectRaw('SUM(financial_obligations.paid_amount) received_amount')
            ->groupBy('universities.public_id', 'universities.name', 'financial_obligations.currency')->get();

        $byCampaign = (clone $obligations)->leftJoin('campaigns', 'campaigns.id', '=', 'financial_obligations.campaign_id')
            ->select('campaigns.public_id as campaign_id', 'campaigns.name as campaign', 'financial_obligations.currency')
            ->selectRaw('SUM(financial_obligations.amount) expected_amount')
            ->selectRaw('SUM(financial_obligations.paid_amount) received_amount')
            ->groupBy('campaigns.public_id', 'campaigns.name', 'financial_obligations.currency')->get();

        return response()->json(['data' => [
            'institution' => ['id' => $institution->public_id, 'name' => $institution->name],
            'totals_by_currency' => $byCurrency,
            'statistics' => [
                'open_obligations' => (clone $obligations)->whereIn('financial_obligations.status', ['PENDING', 'PARTIALLY_PAID'])->count(),
                'pending_payments' => (clone $transactions)->where('payment_transactions.status', 'PENDING')->count(),
                'failed_payments' => (clone $transactions)->where('payment_transactions.status', 'FAILED')->count(),
                'unverified_payments' => (clone $transactions)->where('payment_transactions.status', 'PAID')->whereNull('verified_at')->count(),
                'refunds_count' => (clone $refunds)->count(),
            ],
            'by_university' => $byUniversity,
            'by_campaign' => $byCampaign,
            'recent_payments' => (clone $transactions)->with($this->transactionRelations())->latest('payment_transactions.created_at')->limit(8)->get(),
            'alerts' => [
                'overdue_obligations' => (clone $obligations)->whereIn('financial_obligations.status', ['PENDING', 'PARTIALLY_PAID'])->whereNotNull('due_date')->whereDate('due_date', '<', today())->count(),
                'failed_payments' => (clone $transactions)->where('payment_transactions.status', 'FAILED')->count(),
                'refunds_pending' => (clone $refunds)->where('payment_refunds.status', 'PENDING')->count(),
            ],
        ]]);
    }

    public function obligations(Request $request): JsonResponse
    {
        [$institution, $filters] = $this->context($request);
        $items = $this->obligationsQuery($institution, $filters)->with(['student.user.profile', 'student.university', 'campaign', 'items', 'allocations.transaction'])->latest()->paginate(min($request->integer('per_page', 25), 100));

        return response()->json(['data' => $items]);
    }

    public function transactions(Request $request): JsonResponse
    {
        [$institution, $filters] = $this->context($request);
        $items = $this->transactionsQuery($institution, $filters)->with($this->transactionRelations())->latest('payment_transactions.created_at')->paginate(min($request->integer('per_page', 25), 100));

        return response()->json(['data' => $items]);
    }

    public function refunds(Request $request): JsonResponse
    {
        [$institution, $filters] = $this->context($request);
        $items = $this->refundsQuery($institution, $filters)->with(['transaction.student.user.profile', 'transaction.allocations.obligation.campaign', 'transaction.allocations.obligation.student.university'])->latest('payment_refunds.created_at')->paginate(min($request->integer('per_page', 25), 100));

        return response()->json(['data' => $items]);
    }

    public function manualPayment(Request $request, FinanceService $finance): JsonResponse
    {
        $data = $request->validate([
            'institution_id' => ['required', 'uuid'], 'obligation_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0'], 'currency' => ['required', 'string', 'size:3'],
            'method' => ['required', 'string', 'max:30'], 'payer_reference' => ['required', 'string', 'max:150'],
            'paid_at' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        $institution = $this->institution($request, $data['institution_id']);
        $obligation = FinancialObligation::where('public_id', $data['obligation_id'])->where('institution_id', $institution->id)->firstOrFail();
        abort_unless(strtoupper($data['currency']) === $obligation->currency, 422, 'La devise doit correspondre à celle de l’obligation.');

        $transaction = DB::transaction(function () use ($data, $institution, $obligation, $request, $finance): PaymentTransaction {
            $transaction = PaymentTransaction::create([
                'student_id' => $obligation->student_id, 'institution_id' => $institution->id,
                'provider' => 'MANUAL', 'provider_reference' => (string) Str::uuid(), 'payer_reference' => $data['payer_reference'],
                'amount' => $data['amount'], 'currency' => strtoupper($data['currency']), 'method' => $data['method'],
                'source' => 'MANUAL', 'status' => 'PAID', 'notes' => $data['notes'] ?? null,
                'recorded_by' => $request->user()->id, 'paid_at' => $data['paid_at'],
            ]);
            $finance->allocate($transaction, $obligation, (string) $data['amount']);

            return $transaction;
        });

        return response()->json(['data' => $transaction->load($this->transactionRelations())], 201);
    }

    public function allocate(Request $request, PaymentTransaction $transaction, FinanceService $finance): JsonResponse
    {
        $data = $request->validate(['obligation_id' => ['required', 'uuid'], 'amount' => ['required', 'numeric', 'gt:0']]);
        $institution = $this->transactionInstitution($request, $transaction);
        $obligation = FinancialObligation::where('public_id', $data['obligation_id'])->where('institution_id', $institution->id)->firstOrFail();
        abort_unless($transaction->student_id === $obligation->student_id && $transaction->currency === $obligation->currency, 422, 'Le paiement et l’obligation doivent concerner le même étudiant et la même devise.');
        $finance->allocate($transaction, $obligation, (string) $data['amount']);
        $transaction->update(['institution_id' => $institution->id]);

        return response()->json(['data' => $transaction->fresh()->load($this->transactionRelations())]);
    }

    public function verify(Request $request, PaymentTransaction $transaction): JsonResponse
    {
        $this->transactionInstitution($request, $transaction);
        abort_unless($transaction->status === 'PAID', 422, 'Seul un paiement reçu peut être vérifié.');
        $transaction->update(['verified_by' => $request->user()->id, 'verified_at' => now()]);

        return response()->json(['data' => $transaction->fresh()->load($this->transactionRelations())]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$institution, $filters] = $this->context($request);
        $transactions = $this->transactionsQuery($institution, $filters)->with($this->transactionRelations())->latest('payment_transactions.created_at')->get();

        return response()->streamDownload(function () use ($transactions): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Date', 'Référence', 'Référence payeur', 'Étudiant', 'Université', 'Campagne', 'Montant', 'Devise', 'Statut', 'Source', 'Vérifié']);
            foreach ($transactions as $transaction) {
                $obligation = $transaction->allocations->first()?->obligation;
                fputcsv($stream, [$transaction->created_at, $transaction->provider_reference, $transaction->payer_reference, $transaction->student->user?->name ?? $transaction->student->student_number, $obligation?->student?->university?->name, $obligation?->campaign?->name, $transaction->amount, $transaction->currency, $transaction->status, $transaction->source, $transaction->verified_at ? 'Oui' : 'Non']);
            }
            fclose($stream);
        }, 'operations-financieres-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function context(Request $request): array
    {
        $filters = $request->validate([
            'institution_id' => ['required', 'uuid'], 'status' => ['nullable', 'string', 'max:30'],
            'search' => ['nullable', 'string', 'max:100'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'university_id' => ['nullable', 'uuid'], 'campaign_id' => ['nullable', 'uuid'], 'currency' => ['nullable', 'string', 'size:3'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return [$this->institution($request, $filters['institution_id']), $filters];
    }

    private function institution(Request $request, string $publicId): Institution
    {
        $institution = Institution::where('public_id', $publicId)
            ->whereIn('type', ['UNIVERSITY', 'HOSPITAL'])
            ->firstOrFail();
        abort_unless(app(InstitutionAccess::class)->has($request->user(), $institution->id, [InstitutionRole::FinanceOfficer->value]), 403);

        return $institution;
    }

    private function obligationsQuery(Institution $institution, array $filters): Builder
    {
        $query = FinancialObligation::query()->where('financial_obligations.institution_id', $institution->id);
        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('financial_obligations.status', $status));
        $query->when($filters['currency'] ?? null, fn ($query, $currency) => $query->where('financial_obligations.currency', strtoupper($currency)));
        $query->when($filters['campaign_id'] ?? null, fn ($query, $id) => $query->whereHas('campaign', fn ($campaign) => $campaign->where('public_id', $id)));
        $query->when($filters['university_id'] ?? null, fn ($query, $id) => $query->whereHas('student.university', fn ($university) => $university->where('public_id', $id)));
        $query->when($filters['search'] ?? null, fn ($query, $search) => $query->whereHas('student', fn ($student) => $student->where('student_number', 'like', "%{$search}%")->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%"))));
        $query->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('financial_obligations.created_at', '>=', $date));
        $query->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('financial_obligations.created_at', '<=', $date));

        return $query;
    }

    private function transactionsQuery(Institution $institution, array $filters): Builder
    {
        $query = PaymentTransaction::query()->where('payment_transactions.institution_id', $institution->id);
        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('payment_transactions.status', $status));
        $query->when($filters['currency'] ?? null, fn ($query, $currency) => $query->where('payment_transactions.currency', strtoupper($currency)));
        $query->when($filters['campaign_id'] ?? null, fn ($query, $id) => $query->whereHas('allocations.obligation.campaign', fn ($campaign) => $campaign->where('public_id', $id)));
        $query->when($filters['university_id'] ?? null, fn ($query, $id) => $query->whereHas('student.university', fn ($university) => $university->where('public_id', $id)));
        $query->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested->where('provider_reference', 'like', "%{$search}%")->orWhere('payer_reference', 'like', "%{$search}%")->orWhereHas('student', fn ($student) => $student->where('student_number', 'like', "%{$search}%")->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%")))));
        $query->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('payment_transactions.created_at', '>=', $date));
        $query->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('payment_transactions.created_at', '<=', $date));

        return $query;
    }

    private function refundsQuery(Institution $institution, array $filters): Builder
    {
        $query = PaymentRefund::query()->whereHas('transaction', fn ($transaction) => $transaction->where('institution_id', $institution->id));
        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('payment_refunds.status', $status));
        $query->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('payment_refunds.created_at', '>=', $date));
        $query->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('payment_refunds.created_at', '<=', $date));

        return $query;
    }

    private function transactionInstitution(Request $request, PaymentTransaction $transaction): Institution
    {
        $institutionId = $transaction->institution_id ?? $transaction->allocations()->with('obligation')->first()?->obligation?->institution_id;
        abort_unless($institutionId, 422, 'Ce paiement n’est associé à aucune institution.');
        $institution = Institution::findOrFail($institutionId);
        abort_unless(app(InstitutionAccess::class)->has($request->user(), $institution->id, [InstitutionRole::FinanceOfficer->value]), 403);

        return $institution;
    }

    private function transactionRelations(): array
    {
        return ['student.user.profile', 'student.university', 'institution', 'allocations.obligation.campaign', 'allocations.obligation.student.university', 'refunds', 'recordedBy', 'verifiedBy'];
    }
}
