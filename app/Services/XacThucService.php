<?php

namespace App\Services;
use App\Models\SinhVien;
use App\Models\GiangVien;
use Carbon\Carbon;


class XacThucService
{
    public function xuLyDangNhapBangEmail(string $email)
    {
        $user = null;
        $quyen = null;

        $giangVien = GiangVien::where('email', $email)->where('dang_hoat_dong', 1)->first();
        if ($giangVien) {
            $user = $giangVien;
            $quyen = $giangVien->vai_tro; 
        } else {
            $sinhVien = SinhVien::where('email', $email)->where('dang_hoat_dong', 1)->first();
            if ($sinhVien) {
                $user = $sinhVien;
                $quyen = 'SINH_VIEN';
            }
        }

        if (!$user) {
            return null;
        }

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
