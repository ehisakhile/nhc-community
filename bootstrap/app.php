<?php

use App\Exceptions\SafeException;
use App\Modules\FeatureHub\RequireFeature;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The VPS's nginx reaches the container from Docker's private network.
        // Trust only private addresses: trusting '*' would let anyone set their
        // own IP with X-Forwarded-For and dodge the per-IP sign-in limits.
        $middleware->trustProxies(at: ['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']);

        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'feature' => RequireFeature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Personal data never reaches logs or LoggerAI through an error message.
        $exceptions->map(QueryException::class, fn (QueryException $e) => SafeException::fromQuery($e));
        $exceptions->map(TransportExceptionInterface::class, fn (TransportExceptionInterface $e) => SafeException::fromMail($e));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
