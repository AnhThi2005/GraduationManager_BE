<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class KiemTraQuyen
{
    public function handle(Request $request, Closure $next, ...$quyens): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn chưa đăng nhập vào hệ thống.'
            ], 401);
        }

        foreach ($quyens as $quyen) {
            if ($user->tokenCan($quyen)) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Từ chối truy cập: Bạn không có quyền hạn thực hiện hành động này!'
        ], 403);
    }
}