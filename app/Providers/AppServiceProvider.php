<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Tài khoản (sinh viên/giảng viên) bị khóa (dang_hoat_dong = 0) sau khi đã đăng
        // nhập thì access token cũ vẫn còn hạn (8 tiếng) vẫn phải mất quyền truy cập
        // ngay lập tức — kiểm tra ở đây áp dụng cho MỌI request dùng Sanctum, không cần
        // sửa từng route/middleware auth:sanctum rải rác trong web.php và api.php.
        Sanctum::authenticateAccessTokensUsing(function ($accessToken, bool $isValid) {
            $tokenable = $accessToken->tokenable;

            if (! $tokenable || (isset($tokenable->dang_hoat_dong) && ! $tokenable->dang_hoat_dong)) {
                return false;
            }

            return $isValid;
        });
    }
}
