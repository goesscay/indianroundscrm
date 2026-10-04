<?php

use App\Http\Middleware\EnforceSession;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA cookie auth (stateful domains from SANCTUM_STATEFUL_DOMAINS).
        $middleware->statefulApi();
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        // Per-session security checks (token_version, revocation, idle timeout, account status).
        $middleware->appendToGroup('api', EnforceSession::class);
        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $r) => $r->is('api/*') || $r->is('sanctum/*') || $r->expectsJson();

        $exceptions->shouldRenderJsonWhen(fn (Request $r, Throwable $e) => $isApi($r));

        // All API errors are shaped {"detail": "..."} like the old FastAPI app.
        $exceptions->render(function (AuthenticationException $e, Request $r) use ($isApi) {
            if ($isApi($r)) {
                return response()->json(['detail' => 'Not authenticated'], 401);
            }
        });
        $exceptions->render(function (ValidationException $e, Request $r) use ($isApi) {
            if ($isApi($r)) {
                return response()->json([
                    'detail' => collect($e->errors())->flatten()->first() ?? 'Validation error',
                    'errors' => $e->errors(),
                ], 422);
            }
        });
        $exceptions->render(function (TokenMismatchException $e, Request $r) use ($isApi) {
            if ($isApi($r)) {
                return response()->json(['detail' => 'CSRF token mismatch.'], 419);
            }
        });
        $exceptions->render(function (AuthorizationException $e, Request $r) use ($isApi) {
            if ($isApi($r)) {
                return response()->json(['detail' => $e->getMessage() ?: 'Forbidden'], 403);
            }
        });
        $exceptions->render(function (ModelNotFoundException $e, Request $r) use ($isApi) {
            if ($isApi($r)) {
                return response()->json(['detail' => 'Not found'], 404);
            }
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $r) use ($isApi) {
            if ($isApi($r)) {
                $status = $e->getStatusCode();
                $msg = $e->getMessage() ?: (\Symfony\Component\HttpFoundation\Response::$statusTexts[$status] ?? 'Error');
                if ($status === 404 && str_starts_with($msg, 'The route ')) {
                    $msg = 'Not found';
                }

                return response()->json(['detail' => $msg], $status, $e->getHeaders());
            }
        });
        $exceptions->render(function (Throwable $e, Request $r) use ($isApi) {
            if ($isApi($r) && ! config('app.debug')) {
                return response()->json(['detail' => 'Internal server error'], 500);
            }
        });
    })->create();
