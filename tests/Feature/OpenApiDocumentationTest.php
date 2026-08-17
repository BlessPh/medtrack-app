<?php

namespace Tests\Feature;

use App\OpenApi\OpenApiGenerator;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OpenApiDocumentationTest extends TestCase
{
    public function test_every_api_operation_is_present_in_openapi(): void
    {
        $document = app(OpenApiGenerator::class)->generate();
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $path = '/'.preg_replace('/\{([^}:]+):[^}]+\}/', '{$1}', $route->uri());
            foreach (array_intersect($route->methods(), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']) as $method) {
                $this->assertArrayHasKey($path, $document['paths'], "Chemin OpenAPI absent : {$path}");
                $this->assertArrayHasKey(strtolower($method), $document['paths'][$path], "Opération OpenAPI absente : {$method} {$path}");
            }
        }
    }

    public function test_every_documented_write_has_payload_responses_and_unique_operation_id(): void
    {
        $document = app(OpenApiGenerator::class)->generate();
        $ids = [];
        foreach ($document['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $this->assertNotEmpty($operation['summary']);
                $this->assertNotEmpty($operation['responses']);
                $this->assertNotContains($operation['operationId'], $ids, 'operationId dupliqué : '.$operation['operationId']);
                $ids[] = $operation['operationId'];
                if (isset($operation['requestBody'])) {
                    $schema = array_values($operation['requestBody']['content'])[0]['schema'];
                    $this->assertNotEmpty($schema['properties'], "Propriétés du payload absentes : {$method} {$path}");
                }
            }
        }
    }

    public function test_security_schemes_and_main_schemas_are_declared(): void
    {
        $document = app(OpenApiGenerator::class)->generate();

        $this->assertArrayHasKey('bearerAuth', $document['components']['securitySchemes']);
        $this->assertArrayHasKey('maishaPaySignature', $document['components']['securitySchemes']);
        $this->assertArrayHasKey('ImportedStudent', $document['components']['schemas']);
        $this->assertSame('3.0.3', $document['openapi']);
    }
}
