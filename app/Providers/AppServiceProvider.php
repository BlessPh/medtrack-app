<?php

namespace App\Providers;

use App\Modules\Finance\Contracts\PaymentGateway;
use App\Modules\Finance\Services\MaishaPayGateway;
use App\Modules\Institution\Models\Institution;
use App\Modules\Institution\Policies\InstitutionPolicy;
use App\Modules\Media\Models\Document;
use App\Modules\Media\Policies\DocumentPolicy;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, MaishaPayGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DB::whenQueryingForLongerThan((int) config('app.slow_request_threshold_ms', 500), function (Connection $connection, QueryExecuted $event): void {
            Log::warning('Requêtes SQL lentes cumulées.', ['connection' => $connection->getName(), 'last_query_ms' => $event->time]);
        });
        Gate::policy(Institution::class, InstitutionPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::before(fn ($user): ?bool => app(InstitutionAccess::class)->isSuperAdmin($user) ? true : null);
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('password', fn (Request $request) => Limit::perMinute(3)->by($request->ip()));
        RateLimiter::for('verification', fn (Request $request) => Limit::perMinute(3)->by((string) $request->user()?->id));
        RateLimiter::for('student-registration', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
    }
}
