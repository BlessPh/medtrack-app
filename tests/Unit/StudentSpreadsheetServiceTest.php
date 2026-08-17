<?php

namespace Tests\Unit;

use App\Modules\Academic\Services\StudentSpreadsheetService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class StudentSpreadsheetServiceTest extends TestCase
{
    public function test_it_normalizes_headers_identifiers_and_gender(): void
    {
        $file = $this->spreadsheet([
            ['Numéro matricule', 'Nom', 'Post nom', 'Prenom', 'Genre', 'Date naissance'],
            [' med-009 ', 'Masiala', 'Phambu', 'Bruno', 'Masculin', '2000-01-31'],
        ]);

        $result = app(StudentSpreadsheetService::class)->extract($file);

        $this->assertSame('MED-009', $result['rows'][0]['student']['student_number']);
        $this->assertSame('MALE', $result['rows'][0]['student']['gender']);
        $this->assertTrue($result['rows'][0]['valid']);
    }

    public function test_it_reports_invalid_email_and_date_without_rejecting_the_whole_preview(): void
    {
        $file = $this->spreadsheet([
            ['Matricule', 'Nom', 'Post-nom', 'Prénom', 'Sexe', 'Date de naissance', 'Email'],
            ['MED-010', 'Nom', 'Postnom', 'Prenom', 'F', '31/01/2000', 'invalid'],
        ]);

        $result = app(StudentSpreadsheetService::class)->extract($file);

        $this->assertFalse($result['rows'][0]['valid']);
        $this->assertSame(['INVALID_EMAIL', 'INVALID_DATE'], array_column($result['rows'][0]['errors'], 'code'));
    }

    public function test_it_rejects_a_spreadsheet_without_required_headers(): void
    {
        $this->expectException(ValidationException::class);
        app(StudentSpreadsheetService::class)->extract($this->spreadsheet([['Nom'], ['Masiala']]));
    }

    private function spreadsheet(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'medtrack-unit-sheet-');
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'students.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
