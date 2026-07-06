<?php

namespace App\Services;
use App\Models\SinhVien;
use App\Models\GiangVien;
use Carbon\Carbon;


class XacThucService
{
    public function xuLyDangNhapBangEmail(string $email)
    {
        [$user, $quyen] = $this->timTaiKhoanTheoEmail($email);

        if (!$user) {
            return null;
        }

        return $this->capToken($user, $quyen);
    }

    /**
     * Đăng nhập bằng Google: ưu tiên so khớp theo google_id (mã định danh vĩnh viễn của tài khoản Google,
     * không đổi kể cả khi họ đổi email) đã lưu từ lần đăng nhập trước. Nếu chưa từng liên kết,
     * so khớp theo email như bình thường rồi lưu lại google_id vào đúng bản ghi đó cho lần sau.
     */
    public function xuLyDangNhapBangGoogle(string $googleId, string $email)
    {
        $giangVien = GiangVien::where('google_id', $googleId)->where('dang_hoat_dong', 1)->first();
        if ($giangVien) {
            return $this->capToken($giangVien, $giangVien->vai_tro);
        }

        $sinhVien = SinhVien::where('google_id', $googleId)->where('dang_hoat_dong', 1)->first();
        if ($sinhVien) {
            return $this->capToken($sinhVien, 'SINH_VIEN');
        }

        // Chưa từng đăng nhập Google trước đó -> so khớp theo email, rồi liên kết google_id cho lần sau
        [$user, $quyen] = $this->timTaiKhoanTheoEmail($email);

        if (!$user) {
            return null;
        }

        $user->update(['google_id' => $googleId]);

        return $this->capToken($user, $quyen);
    }

    private function timTaiKhoanTheoEmail(string $email)
    {
        $giangVien = GiangVien::where('email', $email)->where('dang_hoat_dong', 1)->first();
        if ($giangVien) {
            return [$giangVien, $giangVien->vai_tro];
        }

        $sinhVien = SinhVien::where('email', $email)->where('dang_hoat_dong', 1)->first();
        if ($sinhVien) {
            return [$sinhVien, 'SINH_VIEN'];
        }

        return [null, null];
    }

    private function capToken($user, string $quyen)
    {
        $accessTokenResult = $user->createToken('access_token', [$quyen], Carbon::now()->addHours(8));
        $refreshTokenResult = $user->createToken('refresh_token', ['issue-access-token'], Carbon::now()->addDays(7));

        return [
            'role' => $quyen,
            'access_token' => $accessTokenResult->plainTextToken,
            'refresh_token' => $refreshTokenResult->plainTextToken,
            'user' => $user
        ];
    }
}
