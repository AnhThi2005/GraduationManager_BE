<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\XacThucService;
use laravel\Sanctum\PersonalAccessToken;

class MockAuthController extends Controller
{
    protected $xacThucService;

    public function __construct(XacThucService $xacThucService)
    {
        $this->xacThucService = $xacThucService;
    }

    public function dangNhap(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $ketQua = $this->xacThucService->xuLyDangNhapBangEmail($request->email);

        if (!$ketQua) {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản Email không tồn tại trong hệ thống dữ liệu mẫu!'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Đăng nhập giả lập hệ thống thành công!',
            'data' => $ketQua
        ], 200);
    }

    public function lamMoiToken(Request $request)
    {
        $request->validate(['refresh_token' => 'required|string']);

        $tokenModel = PersonalAccessToken::findToken($request->refresh_token);

        if (!$tokenModel || !$tokenModel->tokenCan('issue-access-token') || ($tokenModel->expires_at && $tokenModel->expires_at->isPast())) {
            return response()->json([
                'success' => false,
                'message' => 'Refresh Token không hợp lệ hoặc đã hết hạn. Vui lòng đăng nhập lại!'
            ], 401);
        }

        $user = $tokenModel->tokenable;
        $role = ($tokenModel->tokenable_type === 'App\Models\GiangVien') ? $user->vai_tro : 'SINH_VIEN';

        $newAccessToken = $user->createToken('access_token', [$role], Carbon::now()->addHours(8));

        return response()->json([
            'success' => true,
            'message' => 'Làm mới phiên làm việc thành công!',
            'data' => [
                'access_token' => $newAccessToken->plainTextToken
            ]
        ], 200);
    }

    public function dangXuat(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Đăng xuất thành công. Cặp Token cũ đã được thu hồi và hủy bỏ!'
        ], 200);
    }
}
