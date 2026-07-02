<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\XacThucService;
use Laravel\Sanctum\PersonalAccessToken;
use Carbon\Carbon;
// use OpenApi\Attributes as OA;

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

        // Tìm token trong DB từ chuỗi plainTextToken truyền lên
        $tokenModel = PersonalAccessToken::findToken($request->refresh_token);

        // Kiểm tra token tồn tại, có quyền 'issue-access-token' và chưa hết hạn
        if (!$tokenModel || !$tokenModel->can('issue-access-token') || ($tokenModel->expires_at && $tokenModel->expires_at->isPast())) {
            return response()->json([
                'success' => false,
                'message' => 'Refresh Token không hợp lệ hoặc đã hết hạn. Vui lòng đăng nhập lại!'
            ], 401);
        }

        $user = $tokenModel->tokenable;
        $role = ($tokenModel->tokenable_type === 'App\Models\GiangVien') ? $user->vai_tro : 'SINH_VIEN';

        // Cấp Access Token mới có thời hạn 8 tiếng
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
        // Kiểm tra xem có user nào ứng với token truyền lên không
    if (!$request->user()) {
        return response()->json([
            'success' => false,
            'message' => 'Bạn chưa đăng nhập hoặc Token không hợp lệ!'
        ], 401); // Trả về 401 thay vì để sập lỗi 500
    }

    $token = $request->user()->currentAccessToken();
    if ($token && method_exists($token, 'delete')) {
        $token->delete();
    } else {
        $request->user()->tokens()->delete();
    }

    return response()->json([
        'success' => true,
        'message' => 'Đăng xuất thành công!'
    ], 200);
    }
}