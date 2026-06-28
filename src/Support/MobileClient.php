<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ServerRequestInterface;

final class MobileClient
{
    private const MOBILE_UA_PATTERN = '/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Mobile/i';

    public static function isLikelyMobile(ServerRequestInterface $request): bool
    {
        $viewportWidth = $request->getHeaderLine('Sec-CH-Viewport-Width');
        if ($viewportWidth !== '' && is_numeric($viewportWidth) && (int) $viewportWidth < 768) {
            return true;
        }

        $userAgent = $request->getHeaderLine('User-Agent');

        return $userAgent !== '' && preg_match(self::MOBILE_UA_PATTERN, $userAgent) === 1;
    }
}
