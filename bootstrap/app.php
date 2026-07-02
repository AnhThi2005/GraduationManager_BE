<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;//Sử dụng để loại trừ các tuyến đường API khỏi việc kiểm tra CSRF

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \Spatie\Permission\Middlewares\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middlewares\PermissionMiddleware::class,
        ]);

        $middleware->alias([
            'quyen' => \App\Http\Middleware\KiemTraQuyen::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*',
            'private/*',
            'private/v1/*',
            'v1/file-upload/upload',
            'docs',
            'docs/*',
            'api/documentation',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();