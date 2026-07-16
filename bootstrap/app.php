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
        // Chuẩn hoá MỌI lỗi trên các route API (/private/*, /api/*) về 1 format duy nhất
        // {success: false, message, errors?} — khớp với convention {success,message} đã dùng
        // sẵn thủ công ở đa số controller, không đổi bất kỳ response THÀNH CÔNG nào đang có.
        // Quan trọng: dù APP_DEBUG=true (đang bật ở môi trường này), route API không bao giờ
        // được lộ stack trace/đường dẫn ổ đĩa thật ra ngoài — cờ debug chỉ nên ảnh hưởng tới
        // trang debug HTML khi duyệt web thường, không phải response JSON của API.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            $isApiRequest = $request->is('private/*') || $request->is('api/*') || $request->expectsJson();
            if (! $isApiRequest) {
                return null;
            }

            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], 422);
            }

            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bạn chưa đăng nhập.',
                ], 401);
            }

            if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'Bạn không có quyền thực hiện thao tác này!',
                ], 403);
            }

            if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tìm thấy tài nguyên được yêu cầu.',
                ], 404);
            }

            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'Có lỗi xảy ra.',
                ], $e->getStatusCode());
            }

            // Lỗi không xác định trước — không bao giờ lộ stack trace ra API response,
            // bất kể APP_DEBUG. Chỉ hiện message thật khi debug bật để còn dò lỗi cục bộ.
            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau.',
            ], 500);
        });
    })->create();
