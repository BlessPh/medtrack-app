<?php

namespace App\Modules\Academic\Controllers;

use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\StudentImport;
use App\Modules\Academic\Policies\AcademicPolicy;
use App\Modules\Academic\Services\AcademicImportContext;
use App\Modules\Academic\Services\StudentSpreadsheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class StudentImportController
{
    public function preview(Request $request, AcademicImportContext $context, StudentSpreadsheetService $spreadsheets): JsonResponse
    {
        $data = $request->validate(['university_id' => ['required', 'uuid'], 'promotion_id' => ['required', 'integer'], 'academic_year_id' => ['required', 'integer'], 'file' => ['required', 'file', 'max:10240', 'mimes:xlsx,csv', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv,text/plain,application/csv']]);
        [$university, $promotion, $year] = $context->resolve($data['university_id'], $data['promotion_id'], $data['academic_year_id']);
        abort_unless(app(AcademicPolicy::class)->manage($request->user(), $university->id), 403);
        $result = $spreadsheets->extract($request->file('file'));
        $numbers = collect($result['rows'])->pluck('student.student_number')->filter();
        $duplicates = $numbers->duplicates()->unique();
        $existing = Student::where('university_id', $university->id)
            ->whereIn(DB::raw('UPPER(student_number)'), $numbers->map(fn ($number) => mb_strtoupper($number))->unique())
            ->pluck('student_number')->map(fn ($number) => mb_strtoupper($number));
        foreach ($result['rows'] as &$row) {
            $number = mb_strtoupper($row['student']['student_number'] ?? '');
            if ($number !== '' && $duplicates->contains($number)) {
                $row['errors'][] = ['field' => 'student_number', 'code' => 'DUPLICATE_IN_FILE', 'message' => 'Ce matricule apparaît plusieurs fois dans le fichier.'];
            }
            if ($number !== '' && $existing->contains($number)) {
                $row['errors'][] = ['field' => 'student_number', 'code' => 'ALREADY_EXISTS', 'message' => 'Ce matricule existe déjà dans cette université.'];
            }
            $row['valid'] = $row['errors'] === [];
            $row['selected'] = $row['valid'];
        }
        unset($row);
        $result['summary']['valid'] = collect($result['rows'])->where('valid', true)->count();
        $result['summary']['invalid'] = collect($result['rows'])->where('valid', false)->count();

        $import = StudentImport::create([
            'university_id' => $university->id, 'promotion_id' => $promotion->id,
            'academic_year_reference_id' => $year->id, 'created_by' => $request->user()->id,
            'original_filename' => $request->file('file')->getClientOriginalName(),
            'total_rows' => $result['summary']['total'], 'valid_rows' => $result['summary']['valid'],
            'rejected_rows' => $result['summary']['invalid'], 'status' => 'PREVIEWED',
            'error_report' => collect($result['rows'])->where('valid', false)->values()->all(),
        ]);

        return response()->json(['data' => $result + ['context' => ['import_id' => $import->public_id, 'university_id' => $university->public_id, 'promotion_id' => $promotion->id, 'academic_year_id' => $year->id]]]);
    }

    public function confirm(Request $request, AcademicImportContext $context): JsonResponse
    {
        $data = $request->validate(['import_id' => ['nullable', 'uuid', 'exists:student_imports,public_id'], 'university_id' => ['required', 'uuid'], 'promotion_id' => ['required', 'integer'], 'academic_year_id' => ['required', 'integer'], 'students' => ['required', 'array', 'min:1', 'max:1000'], 'students.*.student_number' => ['required', 'string', 'max:100'], 'students.*.last_name' => ['required', 'string', 'max:100'], 'students.*.middle_name' => ['required', 'string', 'max:100'], 'students.*.first_name' => ['required', 'string', 'max:100'], 'students.*.gender' => ['required', Rule::in(['MALE', 'FEMALE'])], 'students.*.birth_date' => ['required', 'date_format:Y-m-d', 'before:today'], 'students.*.email' => ['nullable', 'email', 'max:255'], 'students.*.phone' => ['nullable', 'string', 'max:30']]);
        [$university, $promotion] = $context->resolve($data['university_id'], $data['promotion_id'], $data['academic_year_id']);
        abort_unless(app(AcademicPolicy::class)->manage($request->user(), $university->id), 403);
        $numbers = collect($data['students'])->map(fn ($student) => mb_strtoupper(trim($student['student_number'])));
        if ($numbers->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['students' => 'Le fichier contient des matricules en double.']);
        }
        $existing = Student::where('university_id', $university->id)->whereIn(DB::raw('UPPER(student_number)'), $numbers)->pluck('student_number');
        if ($existing->isNotEmpty()) {
            throw ValidationException::withMessages(['students' => 'Matricules déjà enregistrés : '.$existing->implode(', ')]);
        }
        $import = StudentImport::query()
            ->when(! empty($data['import_id']), fn ($query) => $query->where('public_id', $data['import_id']))
            ->when(empty($data['import_id']), fn ($query) => $query->where('created_by', $request->user()->id)->latest())
            ->where('university_id', $university->id)->where('status', 'PREVIEWED')->firstOrFail();

        $created = DB::transaction(function () use ($data, $university, $promotion, $import): array {
            $result = [];
            foreach ($data['students'] as $row) {
                $student = Student::create(['university_id' => $university->id, 'student_number' => mb_strtoupper(trim($row['student_number'])), 'status' => 'ACTIVE', 'last_name' => trim($row['last_name']), 'middle_name' => trim($row['middle_name']), 'first_name' => trim($row['first_name']), 'gender' => $row['gender'], 'birth_date' => $row['birth_date'], 'email' => $row['email'] ?? null, 'phone' => $row['phone'] ?? null]);
                $student->enrollments()->create(['promotion_id' => $promotion->id, 'status' => 'ACTIVE', 'enrolled_at' => now()]);
                $result[] = ['id' => $student->public_id, 'student_number' => $student->student_number];
            }

            $import->update(['status' => 'CONFIRMED', 'imported_rows' => count($result), 'confirmed_at' => now()]);
            return $result;
        });

        return response()->json(['data' => ['imported' => count($created), 'students' => $created]], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['university_id' => ['required', 'uuid']]);
        $university = \App\Modules\Institution\Models\Institution::where('public_id', $data['university_id'])->firstOrFail();
        abort_unless(app(AcademicPolicy::class)->view($request->user(), $university->id), 403);
        $imports = StudentImport::with(['promotion.program', 'academicYear', 'author'])->where('university_id', $university->id)->latest()->paginate(20);
        return response()->json($imports);
    }

    public function cancel(Request $request, StudentImport $studentImport): JsonResponse
    {
        abort_unless(app(AcademicPolicy::class)->manage($request->user(), $studentImport->university_id), 403);
        abort_unless($studentImport->status === 'PREVIEWED', 422, 'Seul un import non confirmé peut être annulé.');
        $studentImport->update(['status' => 'CANCELLED', 'cancelled_at' => now()]);
        return response()->json(['data' => $studentImport->fresh()]);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(['matricule', 'nom', 'post_nom', 'prenom', 'sexe', 'date_de_naissance', 'email', 'telephone']));
            $writer->addRow(Row::fromValues(['MED-2026-001', 'KABONGO', 'ILUNGA', 'Marie', 'F', '2002-04-15', 'marie@example.com', '+243000000000']));
            $writer->close();
        }, 'modele-import-etudiants.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function errors(Request $request, StudentImport $studentImport): StreamedResponse
    {
        abort_unless(app(AcademicPolicy::class)->view($request->user(), $studentImport->university_id), 403);
        return response()->streamDownload(function () use ($studentImport): void {
            $output = fopen('php://output', 'wb'); fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['ligne', 'matricule', 'champ', 'code', 'erreur']);
            foreach ($studentImport->error_report ?? [] as $row) foreach ($row['errors'] ?? [] as $error) fputcsv($output, [$row['row_number'] ?? '', $row['student']['student_number'] ?? '', $error['field'] ?? '', $error['code'] ?? '', $error['message'] ?? '']);
            fclose($output);
        }, 'erreurs-import-'.$studentImport->public_id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
