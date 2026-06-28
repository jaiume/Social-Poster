<?php

declare(strict_types=1);

namespace App\Support;

final class ServiceResult
{
    public static function success(string $message = '', mixed $data = null): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'error' => null,
        ];
    }

    public static function failure(
        string $message,
        string $code = 'ERROR',
        array $details = []
    ): array {
        return [
            'success' => false,
            'message' => $message,
            'data' => null,
            'error' => [
                'code' => $code,
                'details' => $details,
            ],
        ];
    }
}
