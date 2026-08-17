<?php

namespace App\OpenApi;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class OpenApiGenerator
{
    public function generate(): array
    {
        $paths = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_intersect($route->methods(), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']) as $method) {
                $path = $this->path($route->uri());
                $paths[$path][strtolower($method)] = $this->operation($route, $method, $path);
            }
        }
        ksort($paths);

        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Medtrack API', 'version' => '1.0.0', 'description' => 'API du monolithe modulaire Medtrack. Les routes privées utilisent un jeton JWT Bearer.'],
            'servers' => [['url' => rtrim((string) config('app.url'), '/'), 'description' => 'Serveur configuré']],
            'tags' => collect($this->tags())->map(fn ($description, $name) => ['name' => $name, 'description' => $description])->values()->all(),
            'paths' => $paths,
            'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT', 'description' => 'Jeton obtenu avec POST /api/v1/auth/login.'], 'maishaPaySignature' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-MaishaPay-Signature']], 'schemas' => $this->schemas(), 'responses' => $this->responses()],
        ];
    }

    private function operation(LaravelRoute $route, string $method, string $path): array
    {
        $relative = substr($path, strlen('/api/v1/'));
        $tag = $this->tag(explode('/', $relative)[0]);
        $key = strtoupper($method).' '.$path;
        $public = str_ends_with($path, '/health') || in_array($key, $this->publicOperations(), true);
        $operation = ['tags' => [$tag], 'summary' => $this->summary($method, $relative), 'operationId' => Str::camel(strtolower($method).'_'.preg_replace('/[{}]/', '', str_replace(['/', '-'], '_', $relative))), 'parameters' => $this->parameters($path, $key), 'responses' => $this->operationResponses($method, $path)];
        if (! $public) {
            $operation['security'] = [['bearerAuth' => []]];
        }
        if ($key === 'POST /api/v1/finance/callbacks/maishapay') {
            $operation['security'] = [['maishaPaySignature' => []]];
        }
        if (isset($this->payloads()[$key])) {
            $operation['requestBody'] = $this->requestBody($key);
        }
        if ($operation['parameters'] === []) {
            unset($operation['parameters']);
        }

        return $operation;
    }

    private function requestBody(string $key): array
    {
        $fields = $this->payloads()[$key] ?? [];
        $multipart = in_array($key, ['POST /api/v1/academic/student-imports/preview', 'POST /api/v1/documents', 'POST /api/v1/auth/profile/avatar'], true);
        $properties = [];
        $required = [];
        foreach ($fields as $name => $definition) {
            $optional = str_starts_with($definition, '?');
            $definition = ltrim($definition, '?');
            $properties[$name] = $this->field($definition, $name);
            if (! $optional) {
                $required[] = $name;
            }
        }
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return ['required' => true, 'content' => [($multipart ? 'multipart/form-data' : 'application/json') => ['schema' => $schema]]];
    }

    private function field(string $type, string $name): array
    {
        if ($type === 'file') {
            return ['type' => 'string', 'format' => 'binary'];
        }
        if (str_starts_with($type, 'array:')) {
            return ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/'.substr($type, 6)]];
        }
        if (str_starts_with($type, 'enum:')) {
            return ['type' => 'string', 'enum' => explode('|', substr($type, 5))];
        }
        if ($type === 'uuid') {
            return ['type' => 'string', 'format' => 'uuid'];
        }
        if ($type === 'email') {
            return ['type' => 'string', 'format' => 'email'];
        }
        if ($type === 'date') {
            return ['type' => 'string', 'format' => 'date'];
        }
        if ($type === 'datetime') {
            return ['type' => 'string', 'format' => 'date-time'];
        }
        if (in_array($type, ['integer', 'boolean', 'number'], true)) {
            return ['type' => $type];
        }
        if ($type === 'object') {
            return ['type' => 'object', 'additionalProperties' => true];
        }

        return ['type' => 'string', 'example' => str_contains($name, 'password') ? 'mot-de-passe-securise' : null];
    }

    private function parameters(string $path, string $key): array
    {
        $parameters = [];
        preg_match_all('/\{([^}]+)\}/', $path, $matches);
        foreach ($matches[1] as $name) {
            $publicId = in_array($name, ['institution', 'student', 'user', 'application', 'internship', 'evaluation', 'document', 'transaction'], true);
            $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'description' => $publicId ? 'Identifiant public UUID.' : 'Identifiant interne numérique.', 'schema' => $publicId ? ['type' => 'string', 'format' => 'uuid'] : ['type' => 'integer']];
        }
        foreach ($this->queries()[$key] ?? [] as $name => $type) {
            $parameters[] = ['name' => $name, 'in' => 'query', 'required' => ! str_starts_with($type, '?'), 'schema' => $this->field(ltrim($type, '?'), $name)];
        }

        return $parameters;
    }

    private function operationResponses(string $method, string $path): array
    {
        $noContent = $method === 'DELETE' || str_ends_with($path, '/logout') || str_ends_with($path, '/read-all');
        $created = $method === 'POST' && ! in_array($path, ['/api/v1/auth/login', '/api/v1/auth/logout', '/api/v1/auth/password/forgot', '/api/v1/auth/password/reset', '/api/v1/auth/email/verification-notification', '/api/v1/finance/callbacks/maishapay', '/api/v1/academic/student-imports/preview'], true);
        $success = $noContent ? '204' : ($created ? '201' : '200');
        $responses = [$success => ['$ref' => '#/components/responses/'.($success === '204' ? 'NoContent' : 'Success')]];
        if (! str_ends_with($path, '/health')) {
            $responses += ['401' => ['$ref' => '#/components/responses/Unauthenticated'], '403' => ['$ref' => '#/components/responses/Forbidden'], '422' => ['$ref' => '#/components/responses/ValidationError'], '429' => ['$ref' => '#/components/responses/TooManyRequests']];
        }

        return $responses;
    }

    private function path(string $uri): string
    {
        return '/'.preg_replace('/\{([^}:]+):[^}]+\}/', '{$1}', $uri);
    }

    private function summary(string $method, string $relative): string
    {
        $verb = ['GET' => 'Consulter', 'POST' => 'Créer ou déclencher', 'PUT' => 'Remplacer', 'PATCH' => 'Mettre à jour', 'DELETE' => 'Supprimer'][$method];

        return $verb.' '.str_replace(['-', '{', '}'], [' ', '', ''], $relative);
    }

    private function tag(string $segment): string
    {
        return ['auth' => 'Authentification', 'institutions' => 'Institutions', 'academic' => 'Académique', 'admissions' => 'Admissions', 'internships' => 'Stages', 'scheduling' => 'Planning et présences', 'assessments' => 'Évaluations', 'finance' => 'Finance', 'documents' => 'Documents', 'notifications' => 'Notifications', 'reporting' => 'Rapports'][$segment] ?? ucfirst($segment);
    }

    private function tags(): array
    {
        return ['Authentification' => 'Session, profil, comptes et inscription étudiante.', 'Institutions' => 'Institutions, structures, membres et rôles.', 'Académique' => 'Référentiel, étudiants, campagnes et imports.', 'Admissions' => 'Candidatures, capacités et admissions.', 'Stages' => 'Stages, parcours, rotations et prolongations.', 'Planning et présences' => 'Planning, biométrie, pointages et corrections.', 'Évaluations' => 'Modèles, évaluations, contestations et décisions.', 'Finance' => 'Obligations, paiements, callbacks et remboursements.', 'Documents' => 'Upload privé, téléchargement et suppression.', 'Notifications' => 'Notifications internes et lecture.', 'Rapports' => 'Tableaux de bord, recherche et CSV.'];
    }

    private function publicOperations(): array
    {
        return ['POST /api/v1/auth/login', 'POST /api/v1/auth/password/forgot', 'POST /api/v1/auth/password/reset', 'POST /api/v1/auth/student-registration/check', 'POST /api/v1/auth/student-registration', 'POST /api/v1/finance/callbacks/maishapay'];
    }

    private function queries(): array
    {
        return [
            'GET /api/v1/academic/current-context' => ['university_id' => 'uuid'],
            'GET /api/v1/academic/programs' => ['university_id' => 'uuid'],
            'GET /api/v1/academic/years' => ['university_id' => 'uuid'],
            'GET /api/v1/academic/promotions' => ['university_id' => 'uuid', 'program_id' => '?integer', 'academic_year_id' => '?integer'],
            'GET /api/v1/academic/students' => ['university_id' => 'uuid', 'per_page' => '?integer'],
            'GET /api/v1/reporting/dashboard' => ['institution_id' => '?uuid'],
            'GET /api/v1/reporting/search' => ['institution_id' => 'uuid', 'type' => 'enum:students|applications|internships|payments', 'q' => '?string', 'status' => '?string', 'per_page' => '?integer'],
            'GET /api/v1/reporting/export' => ['institution_id' => 'uuid', 'type' => 'enum:students|applications|internships|payments'],
            'GET /api/v1/institutions' => ['per_page' => '?integer'],
            'GET /api/v1/auth/users' => ['per_page' => '?integer'],
        ];
    }

    private function payloads(): array
    {
        return [
            'POST /api/v1/auth/login' => ['email' => 'email', 'password' => 'string'],
            'POST /api/v1/auth/password/forgot' => ['email' => 'email'],
            'POST /api/v1/auth/password/reset' => ['token' => 'string', 'email' => 'email', 'password' => 'string', 'password_confirmation' => 'string'],
            'PUT /api/v1/auth/profile' => ['name' => 'string', 'phone' => '?string', 'first_name' => '?string', 'last_name' => '?string', 'gender' => '?string', 'birth_date' => '?date', 'nationality' => '?string', 'address' => '?string', 'city' => '?string', 'country' => '?string'],
            'POST /api/v1/auth/profile/avatar' => ['avatar' => 'file'],
            'POST /api/v1/auth/student-registration/check' => ['university_id' => 'uuid', 'promotion_id' => 'integer', 'academic_year_id' => 'integer', 'student_number' => 'string'],
            'POST /api/v1/auth/student-registration' => ['registration_token' => 'string', 'email' => 'email', 'phone' => '?string', 'password' => 'string', 'password_confirmation' => 'string', 'nationality' => '?string', 'address' => '?string', 'city' => '?string', 'country' => '?string'],
            'PATCH /api/v1/auth/users/{user}/status' => ['status' => 'enum:ACTIVE|SUSPENDED|DISABLED'],
            'POST /api/v1/institutions' => $this->institutionPayload(),
            'PUT /api/v1/institutions/{institution}' => $this->institutionPayload(),
            'PATCH /api/v1/institutions/{institution}/status' => ['status' => 'enum:ACTIVE|SUSPENDED|DISABLED'],
            'POST /api/v1/institutions/{institution}/units' => ['parent_id' => '?integer', 'type' => 'string', 'code' => '?string', 'name' => 'string'],
            'POST /api/v1/institutions/{institution}/addresses' => ['label' => '?string', 'address_line' => 'string', 'city' => 'string', 'province' => '?string', 'country' => '?string', 'latitude' => '?number', 'longitude' => '?number', 'is_primary' => '?boolean'],
            'POST /api/v1/institutions/{institution}/contacts' => ['type' => 'enum:EMAIL|PHONE|WHATSAPP', 'value' => 'string', 'label' => '?string', 'is_primary' => '?boolean'],
            'POST /api/v1/institutions/{institution}/members' => ['user_id' => 'uuid', 'role' => 'enum:INSTITUTION_ADMIN|ACADEMIC_MANAGER|HOSPITAL_MANAGER|SUPERVISOR|FINANCE_OFFICER|STUDENT|MEMBER'],
            'POST /api/v1/academic/programs' => ['university_id' => 'uuid', 'code' => 'string', 'name' => 'string', 'degree_type' => '?string', 'duration_years' => '?integer'],
            'POST /api/v1/academic/years' => ['institution_id' => 'uuid', 'label' => 'string', 'starts_on' => 'date', 'ends_on' => 'date'],
            'POST /api/v1/academic/promotions' => ['program_id' => 'integer', 'level_id' => 'integer', 'academic_year_id' => 'integer', 'name' => 'string'],
            'POST /api/v1/academic/students' => ['university_id' => 'uuid', 'user_id' => '?uuid', 'national_reference' => '?string', 'student_number' => 'string', 'promotion_id' => '?integer'],
            'POST /api/v1/academic/campaigns' => ['university_id' => 'uuid', 'academic_year_id' => 'integer', 'name' => 'string', 'regime' => '?string', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'promotion_ids' => 'array:IntegerListItem'],
            'PATCH /api/v1/academic/campaigns/{campaign}/status' => ['status' => 'enum:OPEN|CLOSED|CANCELLED'],
            'POST /api/v1/academic/student-imports/preview' => ['university_id' => 'uuid', 'promotion_id' => 'integer', 'academic_year_id' => 'integer', 'file' => 'file'],
            'POST /api/v1/academic/student-imports/confirm' => ['university_id' => 'uuid', 'promotion_id' => 'integer', 'academic_year_id' => 'integer', 'students' => 'array:ImportedStudent'],
            'POST /api/v1/admissions/applications' => ['campaign_id' => 'integer', 'preferred_hospital_id' => '?uuid', 'motivation' => '?string'],
            'PATCH /api/v1/admissions/applications/{application}/reject' => ['review_note' => 'string'],
            'POST /api/v1/admissions/applications/{application}/accept' => ['capacity_pool_id' => 'integer'],
            'POST /api/v1/admissions/capacities' => ['campaign_hospital_id' => 'integer', 'level_id' => '?integer', 'total_places' => 'integer'],
            'POST /api/v1/internships/templates' => ['name' => 'string', 'steps' => 'array:PathStepInput'],
            'POST /api/v1/internships' => ['admission_id' => 'uuid', 'path_template_id' => '?integer', 'supervisor_id' => '?uuid', 'starts_on' => 'date'],
            'POST /api/v1/internships/{internship}/rotations' => ['path_step_id' => '?integer', 'institution_unit_id' => '?integer', 'starts_on' => 'date', 'ends_on' => 'date'],
            'PATCH /api/v1/internships/{internship}/status' => ['status' => 'enum:ACTIVE|COMPLETED|CANCELLED'],
            'POST /api/v1/internships/rotations/{rotation}/extensions' => ['new_end_date' => 'date', 'reason' => 'string'],
            'PATCH /api/v1/internships/rotations/{rotation}/status' => ['status' => 'enum:ACTIVE|COMPLETED|CANCELLED'],
            'POST /api/v1/scheduling/schedules' => ['internship_id' => 'uuid', 'name' => 'string', 'starts_on' => 'date', 'ends_on' => 'date'],
            'POST /api/v1/scheduling/schedules/{schedule}/entries' => ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'activity_type' => 'string', 'location' => '?string'],
            'POST /api/v1/scheduling/biometric-devices' => ['institution_id' => 'uuid', 'code' => 'string', 'name' => 'string', 'location' => '?string'],
            'POST /api/v1/scheduling/attendance' => ['student_id' => 'uuid', 'schedule_entry_id' => '?integer', 'type' => 'enum:CHECK_IN|CHECK_OUT', 'recorded_at' => 'datetime', 'source' => 'enum:MANUAL|BIOMETRIC', 'device_code' => '?string'],
            'POST /api/v1/scheduling/attendance/{record}/corrections' => ['corrected_at' => 'datetime', 'reason' => 'string'],
            'PATCH /api/v1/scheduling/corrections/{correction}' => ['status' => 'enum:APPROVED|REJECTED', 'review_note' => '?string'],
            'POST /api/v1/assessments/templates' => ['institution_id' => 'uuid', 'name' => 'string', 'criteria' => 'array:EvaluationCriterion'],
            'POST /api/v1/assessments/evaluations' => ['rotation_id' => 'integer', 'template_id' => 'integer', 'answers' => 'object'],
            'POST /api/v1/assessments/evaluations/{evaluation}/disputes' => ['reason' => 'string'],
            'PATCH /api/v1/assessments/disputes/{dispute}' => ['resolution' => 'string'],
            'POST /api/v1/assessments/internships/{internship}/decision' => ['decision' => 'enum:VALIDATED|FAILED|REPEAT', 'comments' => '?string'],
            'POST /api/v1/finance/obligations' => ['student_id' => 'uuid', 'institution_id' => 'uuid', 'type' => 'string', 'description' => 'string', 'currency' => 'string', 'items' => 'array:FinancialItem'],
            'POST /api/v1/finance/payments' => ['obligation_id' => 'uuid', 'amount' => 'number', 'method' => 'string'],
            'POST /api/v1/finance/callbacks/maishapay' => ['reference' => 'string', 'status' => 'enum:PAID|FAILED', 'obligation_id' => '?uuid', 'amount' => '?number'],
            'POST /api/v1/finance/transactions/{transaction}/refunds' => ['amount' => 'number', 'reason' => 'string'],
            'POST /api/v1/documents' => ['owner_type' => 'enum:student|internship', 'owner_id' => 'uuid', 'collection' => 'enum:identity|proof|evaluation', 'file' => 'file'],
        ];
    }

    private function institutionPayload(): array
    {
        return ['type' => 'string', 'name' => 'string', 'short_name' => '?string', 'registration_number' => '?string', 'description' => '?string', 'website' => '?string'];
    }

    private function schemas(): array
    {
        return [
            'ApiDataResponse' => ['type' => 'object', 'properties' => ['data' => ['description' => 'Ressource, collection ou résultat métier.']]],
            'ErrorResponse' => ['type' => 'object', 'required' => ['message'], 'properties' => ['message' => ['type' => 'string'], 'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]]]],
            'ImportedStudent' => ['type' => 'object', 'required' => ['student_number', 'last_name', 'middle_name', 'first_name', 'gender', 'birth_date'], 'properties' => ['student_number' => ['type' => 'string'], 'last_name' => ['type' => 'string'], 'middle_name' => ['type' => 'string'], 'first_name' => ['type' => 'string'], 'gender' => ['type' => 'string', 'enum' => ['MALE', 'FEMALE']], 'birth_date' => ['type' => 'string', 'format' => 'date'], 'email' => ['type' => 'string', 'format' => 'email', 'nullable' => true], 'phone' => ['type' => 'string', 'nullable' => true]]],
            'PathStepInput' => ['type' => 'object', 'required' => ['name', 'duration_days'], 'properties' => ['name' => ['type' => 'string'], 'duration_days' => ['type' => 'integer', 'minimum' => 1]]],
            'EvaluationCriterion' => ['type' => 'object', 'required' => ['key', 'maximum'], 'properties' => ['key' => ['type' => 'string'], 'maximum' => ['type' => 'number', 'exclusiveMinimum' => 0]]],
            'FinancialItem' => ['type' => 'object', 'required' => ['label', 'quantity', 'unit_amount'], 'properties' => ['label' => ['type' => 'string'], 'quantity' => ['type' => 'number', 'exclusiveMinimum' => 0], 'unit_amount' => ['type' => 'number', 'minimum' => 0]]],
            'IntegerListItem' => ['type' => 'integer'],
        ];
    }

    private function responses(): array
    {
        $json = fn (string $schema) => ['description' => $schema === 'ApiDataResponse' ? 'Opération réussie.' : 'Erreur.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$schema]]]];

        return ['Success' => $json('ApiDataResponse'), 'NoContent' => ['description' => 'Opération réussie sans contenu.'], 'Unauthenticated' => ['description' => 'Session absente ou callback non signé.', 'content' => $json('ErrorResponse')['content']], 'Forbidden' => ['description' => 'Compte inactif, rôle ou institution non autorisé.', 'content' => $json('ErrorResponse')['content']], 'ValidationError' => ['description' => 'Payload ou règle métier invalide.', 'content' => $json('ErrorResponse')['content']], 'TooManyRequests' => ['description' => 'Limite de requêtes dépassée.', 'content' => $json('ErrorResponse')['content']]];
    }
}
