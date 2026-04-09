<?php

declare(strict_types=1);

namespace Foundry\Context;

final class ContextExecutionReadiness
{
    /**
     * @return array{can_proceed:bool,requires_repair:bool}
     */
    public static function fromDoctorStatus(string $status): array
    {
        return self::fromCanProceed(in_array($status, ['ok', 'warning'], true));
    }

    /**
     * @return array{can_proceed:bool,requires_repair:bool}
     */
    public static function fromAlignmentStatus(string $status): array
    {
        return self::fromCanProceed(in_array($status, ['ok', 'warning'], true));
    }

    /**
     * @return array{can_proceed:bool,requires_repair:bool}
     */
    public static function fromDoctorAndAlignment(string $doctorStatus, string $alignmentStatus): array
    {
        return self::fromCanProceed(
            in_array($doctorStatus, ['ok', 'warning'], true)
                && in_array($alignmentStatus, ['ok', 'warning'], true),
        );
    }

    public static function verifyStatus(string $doctorStatus, string $alignmentStatus): string
    {
        return self::fromDoctorAndAlignment($doctorStatus, $alignmentStatus)['can_proceed']
            ? 'pass'
            : 'fail';
    }

    /**
     * @return array{can_proceed:bool,requires_repair:bool}
     */
    public static function fromVerifyStatus(string $status): array
    {
        return self::fromCanProceed($status === 'pass');
    }

    /**
     * @return array{can_proceed:bool,requires_repair:bool}
     */
    private static function fromCanProceed(bool $canProceed): array
    {
        return [
            'can_proceed' => $canProceed,
            'requires_repair' => !$canProceed,
        ];
    }
}
