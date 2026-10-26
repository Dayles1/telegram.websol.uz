<?php

use App\Http\Middleware\RecordApiUsage;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\StatsAccess;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SetLocale::class]);
        $middleware->api(prepend: [RecordApiUsage::class], append: [SetLocale::class]);
        $middleware->throttleApi('api');
        $middleware->alias(['stats' => StatsAccess::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // One error shape for the whole API: always "status" and "message" (+ "errors" on 422).
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/*')
            ? response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401)
            : null);
        $exceptions->render(fn (ValidationException $e, Request $request) => $request->is('api/*')
            ? response()->json(['status' => 'validation_error', 'message' => $e->getMessage(), 'errors' => $e->errors()], $e->status)
            : null);
        $exceptions->render(fn (HttpExceptionInterface $e, Request $request) => $request->is('api/*')
            ? response()->json([
                'status' => 'error',
                'message' => $e->getPrevious() instanceof ModelNotFoundException
                    ? 'Not found.'
                    : ($e->getMessage() ?: Response::$statusTexts[$e->getStatusCode()] ?? 'Error'),
            ], $e->getStatusCode(), $e->getHeaders())
            : null);
    })->create();
