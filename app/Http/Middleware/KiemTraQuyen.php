<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class KiemTraQuyen
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() || !$request->user()->tokenCan($quyen)) {
            return response()->json([
                'success' => false,
                'message' => 'Từ chối truy cập: Bạn không có quyền hạn thực hiện hành động này!'
            ], 403);
        }
        return $next($request);
    }
}
