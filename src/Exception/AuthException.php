<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Exception;

/**
 * 401 (bad token / signature), 402 (unpaid), 403 (plan / sig failed).
 *
 * NO fallback swap: the customer still sees native results (graceful), but the
 * merchant must be alerted via an admin notice — these are config/billing
 * problems, not transient failures.
 */
final class AuthException extends QuisslyException
{
    /** @var string Quissly's reason for refusing, as it said it ('' when it gave none) */
    private $detail;

    public function __construct(string $message, int $httpStatus = 0, ?\Throwable $previous = null, string $detail = '')
    {
        parent::__construct($message, $httpStatus, $previous);
        $this->detail = $detail;
    }

    public function detail(): string
    {
        return $this->detail;
    }

    public function shouldFallbackToNative(): bool
    {
        return false;
    }

    public function isMerchantAlert(): bool
    {
        return true;
    }
}
