<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\XacThucService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;

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

        if (! $ketQua) {
            if ($this->xacThucService->laTaiKhoanBiKhoa($request->email)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ Admin để được hỗ trợ.',
                ], 403);
            }

            return response()->json([
                'success' => false,
                'message' => 'Tài khoản Email không tồn tại trong hệ thống dữ liệu mẫu!',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Đăng nhập giả lập hệ thống thành công!',
            'data' => $ketQua,
        ], 200);
    }

    /**
     * Đăng nhập bằng Google — nhận ID token (credential) do Google Identity Services trả về
     * ở phía frontend, xác minh trực tiếp với Google rồi tái dùng đúng logic tìm-email hiện có.
     */
    public function dangNhapGoogle(Request $request)
    {
        $request->validate(['credential' => 'required|string']);

        $verifyRes = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $request->input('credential'),
        ]);

        if (! $verifyRes->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Token Google không hợp lệ hoặc đã hết hạn.',
            ], 401);
        }

        $payload = $verifyRes->json();

        $expectedClientId = config('services.google.client_id');
        if (! $expectedClientId || ($payload['aud'] ?? null) !== $expectedClientId) {
            return response()->json([
                'success' => false,
                'message' => 'Token Google không hợp lệ (sai ứng dụng).',
            ], 401);
        }

        $email = $payload['email'] ?? null;
        $googleId = $payload['sub'] ?? null;
        if (! $email || ! $googleId || ($payload['email_verified'] ?? 'false') !== 'true') {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản Google chưa xác minh email.',
            ], 401);
        }

        $ketQua = $this->xacThucService->xuLyDangNhapBangGoogle($googleId, $email);

        if (! $ketQua) {
            if ($this->xacThucService->laTaiKhoanBiKhoa($email, $googleId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ Admin để được hỗ trợ.',
                ], 403);
            }

            return response()->json([
                'success' => false,
                'message' => "Tài khoản Google ({$email}) chưa được đăng ký trong hệ thống.",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Đăng nhập bằng Google thành công!',
            'data' => $ketQua,
        ], 200);
    }

    public function lamMoiToken(Request $request)
    {
        $request->validate(['refresh_token' => 'required|string']);

        // Tìm token trong DB từ chuỗi plainTextToken truyền lên
        $tokenModel = PersonalAccessToken::findToken($request->refresh_token);

        // Kiểm tra token tồn tại, có quyền 'issue-access-token' và chưa hết hạn
        if (! $tokenModel || ! $tokenModel->can('issue-access-token') || ($tokenModel->expires_at && $tokenModel->expires_at->isPast())) {
            return response()->json([
                'success' => false,
                'message' => 'Refresh Token không hợp lệ hoặc đã hết hạn. Vui lòng đăng nhập lại!',
            ], 401);
        }

        $user = $tokenModel->tokenable;
        $role = ($tokenModel->tokenable_type === 'App\Models\GiangVien') ? $user->vai_tro : 'SINH_VIEN';

        // Cấp Access Token mới có thời hạn 8 tiếng (dùng chung 1 nơi quy định quyền với lúc đăng nhập)
        $newAccessToken = $user->createToken('access_token', XacThucService::tinhDanhSachQuyen($role), Carbon::now()->addHours(8));

        return response()->json([
            'success' => true,
            'message' => 'Làm mới phiên làm việc thành công!',
            'data' => [
                'access_token' => $newAccessToken->plainTextToken,
            ],
        ], 200);
    }

    public function dangXuat(Request $request)
    {
        // Kiểm tra xem có user nào ứng với token truyền lên không
        if (! $request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn chưa đăng nhập hoặc Token không hợp lệ!',
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
            'message' => 'Đăng xuất thành công!',
        ], 200);
    }
}
