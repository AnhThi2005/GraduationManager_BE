<?php

use App\Http\Middleware\KiemTraQuyen;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middlewares\PermissionMiddleware;
use Spatie\Permission\Middlewares\RoleMiddleware;

// Sử dụng để loại trừ các tuyến đường API khỏi việc kiểm tra CSRF

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Đặt các proxy đáng tin cậy để xử lý các yêu cầu từ các proxy ngược (reverse proxies)
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);

        $middleware->alias([
            'quyen' => KiemTraQuyen::class,
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
