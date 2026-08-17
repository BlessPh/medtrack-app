<?php

namespace App\Console\Commands;

use App\OpenApi\OpenApiGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateOpenApiCommand extends Command
{
    protected $signature = 'api:docs';

    protected $description = 'Génère la spécification OpenAPI complète utilisée par L5-Swagger.';

    public function handle(OpenApiGenerator $generator): int
    {
        $directory = storage_path('api-docs');
        File::ensureDirectoryExists($directory);
        File::put($directory.'/api-docs.json', json_encode($generator->generate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->info('Documentation générée : storage/api-docs/api-docs.json');

        return self::SUCCESS;
    }
}
