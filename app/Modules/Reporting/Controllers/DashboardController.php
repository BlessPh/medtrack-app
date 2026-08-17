<?php

namespace App\Modules\Reporting\Controllers;

use App\Modules\Academic\Models\Student;
use App\Modules\Institution\Models\Institution;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $institution = $this->institution($request);
        if (! $institution) {
            return $this->studentDashboard($user->id);
        }
        $id = $institution->id;

        return response()->json(['data' => [
            'institution' => ['id' => $institution->public_id, 'name' => $institution->name, 'type' => $institution->type],
            'students' => DB::table('students')->where('university_id', $id)->whereNull('deleted_at')->count(),
            'pending_applications' => DB::table('applications')->join('campaigns', 'campaigns.id', '=', 'applications.campaign_id')->where('campaigns.university_id', $id)->where('applications.status', 'SUBMITTED')->count(),
            'available_capacity' => DB::table('capacity_pools')->join('campaign_hospitals', 'campaign_hospitals.id', '=', 'capacity_pools.campaign_hospital_id')->where(fn ($q) => $q->where('campaign_hospitals.hospital_id', $id)->orWhereIn('campaign_hospitals.campaign_id', DB::table('campaigns')->select('id')->where('university_id', $id)))->selectRaw('COALESCE(SUM(total_places - reserved_places), 0) AS total')->value('total'),
            'active_internships' => DB::table('internships')->where(fn ($q) => $q->where('hospital_id', $id)->orWhereIn('student_id', DB::table('students')->select('id')->where('university_id', $id)))->where('status', 'ACTIVE')->count(),
            'pending_corrections' => DB::table('attendance_corrections')->join('attendance_records', 'attendance_records.id', '=', 'attendance_corrections.attendance_record_id')->join('students', 'students.id', '=', 'attendance_records.student_id')->where('students.university_id', $id)->where('attendance_corrections.status', 'PENDING')->count(),
            'draft_evaluations' => DB::table('evaluations')->join('students', 'students.id', '=', 'evaluations.student_id')->where('students.university_id', $id)->where('evaluations.status', 'DRAFT')->count(),
            'unpaid_amount' => DB::table('financial_obligations')->where('institution_id', $id)->whereIn('status', ['PENDING', 'PARTIALLY_PAID'])->selectRaw('COALESCE(SUM(amount - paid_amount), 0) AS total')->value('total'),
            'recent_payments' => DB::table('payment_transactions')->join('students', 'students.id', '=', 'payment_transactions.student_id')->where('students.university_id', $id)->where('payment_transactions.status', 'PAID')->where('payment_transactions.paid_at', '>=', now()->subDays(30))->count(),
            'unread_notifications' => $user->unreadNotifications()->count(),
        ]]);
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['institution_id' => ['required', 'uuid'], 'type' => ['required', 'in:students,applications,internships,payments'], 'q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'string', 'max:30'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $institution = $this->institution($request, $data['institution_id']);
        abort_unless($institution, 403);
        $query = $this->query($data['type'], $institution->id);
        if (! empty($data['q'])) {
            $query->where(fn ($q) => $q->where('search_name', 'like', '%'.$data['q'].'%')->orWhere('search_reference', 'like', '%'.$data['q'].'%'));
        }
        if (! empty($data['status'])) {
            $query->where('search_status', $data['status']);
        }

        return response()->json(['data' => $query->orderByDesc('search_date')->paginate($data['per_page'] ?? 25)]);
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate(['institution_id' => ['required', 'uuid'], 'type' => ['required', 'in:students,applications,internships,payments']]);
        $institution = $this->institution($request, $data['institution_id']);
        abort_unless($institution, 403);

        return response()->streamDownload(function () use ($data, $institution): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['reference', 'name', 'status', 'date']);
            $this->query($data['type'], $institution->id)->orderBy('search_date')->chunk(500, function ($rows) use ($output): void {
                foreach ($rows as $row) {
                    fputcsv($output, [$row->search_reference, $row->search_name, $row->search_status, $row->search_date]);
                }
            });
            fclose($output);
        }, $data['type'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function institution(Request $request, ?string $publicId = null): ?Institution
    {
        $publicId ??= $request->string('institution_id')->toString();
        if ($publicId === '') {
            return null;
        }
        $institution = Institution::where('public_id', $publicId)->firstOrFail();
        abort_unless(app(InstitutionAccess::class)->isSuperAdmin($request->user()) || $request->user()->institutions()->whereKey($institution->id)->exists(), 403);

        return $institution;
    }

    private function studentDashboard(int $userId): JsonResponse
    {
        $student = Student::where('user_id', $userId)->first();
        abort_unless($student, 403, 'Aucun contexte institutionnel ou étudiant.');

        return response()->json(['data' => ['student' => $student->public_id, 'active_internships' => DB::table('internships')->where('student_id', $student->id)->where('status', 'ACTIVE')->count(), 'pending_obligations' => DB::table('financial_obligations')->where('student_id', $student->id)->whereIn('status', ['PENDING', 'PARTIALLY_PAID'])->count(), 'unread_notifications' => request()->user()->unreadNotifications()->count()]]);
    }

    private function query(string $type, int $institutionId): Builder
    {
        $query = match ($type) {
            'students' => DB::table('students')->leftJoin('users', 'users.id', '=', 'students.user_id')->where('students.university_id', $institutionId)->whereNull('students.deleted_at')->selectRaw('students.public_id search_reference, COALESCE(users.name, students.student_number) search_name, students.status search_status, students.created_at search_date'),
            'applications' => DB::table('applications')->join('campaigns', 'campaigns.id', '=', 'applications.campaign_id')->join('students', 'students.id', '=', 'applications.student_id')->leftJoin('users', 'users.id', '=', 'students.user_id')->where('campaigns.university_id', $institutionId)->selectRaw('applications.public_id search_reference, COALESCE(users.name, students.student_number) search_name, applications.status search_status, applications.created_at search_date'),
            'internships' => DB::table('internships')->join('students', 'students.id', '=', 'internships.student_id')->leftJoin('users', 'users.id', '=', 'students.user_id')->where(fn ($q) => $q->where('internships.hospital_id', $institutionId)->orWhere('students.university_id', $institutionId))->selectRaw('internships.public_id search_reference, COALESCE(users.name, students.student_number) search_name, internships.status search_status, internships.created_at search_date'),
            'payments' => DB::table('payment_transactions')->join('students', 'students.id', '=', 'payment_transactions.student_id')->leftJoin('users', 'users.id', '=', 'students.user_id')->where('students.university_id', $institutionId)->selectRaw('payment_transactions.public_id search_reference, COALESCE(users.name, students.student_number) search_name, payment_transactions.status search_status, payment_transactions.created_at search_date'),
        };

        return DB::query()->fromSub($query, 'search_results');
    }
}
