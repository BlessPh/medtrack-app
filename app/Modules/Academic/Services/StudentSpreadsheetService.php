<?php

namespace App\Modules\Academic\Services;

use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

class StudentSpreadsheetService
{
    private const ALIASES = [
        'student_number' => ['matricule', 'numero_matricule', 'numero_d_inscription', 'student_number'],
        'last_name' => ['nom', 'last_name', 'surname'],
        'middle_name' => ['post_nom', 'postnom', 'middle_name'],
        'first_name' => ['prenom', 'first_name', 'given_name'],
        'gender' => ['sexe', 'genre', 'gender'],
        'birth_date' => ['date_de_naissance', 'date_naissance', 'birth_date', 'date_of_birth'],
        'email' => ['email', 'courriel', 'adresse_email'],
        'phone' => ['telephone', 'phone', 'numero_telephone'],
    ];

    private const REQUIRED = ['student_number', 'last_name', 'middle_name', 'first_name', 'gender', 'birth_date'];

    public function extract(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $reader = $this->reader($extension);
        $rows = [];
        $mapping = null;
        $headers = [];
        try {
            $reader->open($file->getRealPath());
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $index => $row) {
                    $cells = $row->getCells();
                    if (count($cells) > 30) {
                        throw ValidationException::withMessages(['file' => 'Le fichier dépasse 30 colonnes.']);
                    }
                    if ($index === 1) {
                        $headers = array_map(fn ($cell) => trim((string) $cell->getValue()), $cells);
                        $mapping = $this->mapping($headers);

                        continue;
                    }
                    if (count($rows) >= 1000) {
                        throw ValidationException::withMessages(['file' => 'Le fichier dépasse 1 000 étudiants.']);
                    }
                    $values = [];
                    $formulaColumns = [];
                    foreach ($cells as $column => $cell) {
                        if ($cell instanceof FormulaCell) {
                            $formulaColumns[$column] = true;
                            $values[$column] = null;
                        } else {
                            $value = $cell->getValue();
                            $values[$column] = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : trim((string) ($value ?? ''));
                        }
                    }
                    if (collect($values)->filter(fn ($value) => $value !== '')->isEmpty()) {
                        continue;
                    }
                    $rows[] = $this->row($index, $values, $formulaColumns, $mapping);
                }
                break;
            }
        } finally {
            $reader->close();
        }

        return ['format' => strtoupper($extension), 'columns' => collect($mapping)->map(fn ($column, $field) => ['field' => $field, 'source' => $headers[$column]])->values(), 'rows' => $rows, 'summary' => ['total' => count($rows), 'valid' => collect($rows)->where('valid', true)->count(), 'invalid' => collect($rows)->where('valid', false)->count()]];
    }

    private function reader(string $extension): ReaderInterface
    {
        return match ($extension) {
            'xlsx' => new XlsxReader,
            'csv' => new CsvReader,
            default => throw ValidationException::withMessages(['file' => 'Seuls les fichiers XLSX et CSV sont acceptés.']),
        };
    }

    private function mapping(array $headers): array
    {
        $normalized = collect($headers)->mapWithKeys(fn ($header, $index) => [$this->normalize($header) => $index]);
        $mapping = [];
        foreach (self::ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                if ($normalized->has($alias)) {
                    $mapping[$field] = $normalized[$alias];
                    break;
                }
            }
        }
        foreach (self::REQUIRED as $field) {
            if (! isset($mapping[$field])) {
                throw ValidationException::withMessages(['file' => "Colonne obligatoire absente : {$field}."]);
            }
        }

        return $mapping;
    }

    private function row(int $number, array $values, array $formulaColumns, array $mapping): array
    {
        $student = [];
        $errors = [];
        foreach ($mapping as $field => $column) {
            if (isset($formulaColumns[$column])) {
                $errors[] = ['field' => $field, 'code' => 'FORMULA_NOT_ALLOWED', 'message' => 'Les formules Excel ne sont pas acceptées.'];

                continue;
            }
            $student[$field] = mb_substr(trim((string) ($values[$column] ?? '')), 0, 255);
        }
        $student['student_number'] = mb_strtoupper($student['student_number'] ?? '');
        $student['gender'] = $this->gender($student['gender'] ?? '');
        foreach (self::REQUIRED as $field) {
            if (($student[$field] ?? '') === '') {
                $errors[] = ['field' => $field, 'code' => 'REQUIRED', 'message' => 'Valeur obligatoire.'];
            }
        }
        if (($student['email'] ?? '') !== '' && ! filter_var($student['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = ['field' => 'email', 'code' => 'INVALID_EMAIL', 'message' => 'Adresse e-mail invalide.'];
        }
        if (($student['birth_date'] ?? '') !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $student['birth_date'])) {
            $errors[] = ['field' => 'birth_date', 'code' => 'INVALID_DATE', 'message' => 'Date attendue au format AAAA-MM-JJ.'];
        }

        return ['row_number' => $number, 'selected' => $errors === [], 'valid' => $errors === [], 'student' => $student, 'errors' => $errors];
    }

    private function normalize(string $value): string
    {
        return (string) Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_');
    }

    private function gender(string $value): string
    {
        return match ($this->normalize($value)) {
            'm', 'male', 'masculin', 'homme' => 'MALE', 'f', 'female', 'feminin', 'femme' => 'FEMALE', default => mb_strtoupper(trim($value))
        };
    }
}
