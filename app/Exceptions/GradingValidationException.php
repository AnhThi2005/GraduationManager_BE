<?php

namespace App\Exceptions;

use Exception;

/**
 * Dùng riêng để báo lỗi validate/phân quyền có kiểm soát trong luồng chấm điểm
 * (ví dụ trong DiemController::saveScores()), tách biệt hẳn với RuntimeException
 * gốc để không bao giờ vô tình bắt nhầm QueryException thật (QueryException của
 * Laravel cũng kế thừa RuntimeException, và getCode() của nó trả về mã SQLSTATE
 * chứ không phải HTTP status hợp lệ).
 */
class GradingValidationException extends Exception
{
    public function __construct(string $message, private int $statusCode = 400)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
