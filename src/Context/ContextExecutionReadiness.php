<?php

declare(strict_types=1);

namespace Foundry\Context;

final class ContextExecutionReadiness
{
    public const NON_CONSUMABLE_REASON = 'context_not_consumable';
    public const NON_CONSUMABLE_REQUIRED_ACTION = 'Run `php bin/foundry verify context --json` and resolve all issues before proceeding.';

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
     * @param list<string> $requiredActions
     */
    public static function isConsumable(string $doctorStatus, string $alignmentStatus, array $requiredActions): bool
    {
        return $doctorStatus === 'ok'
            && $alignmentStatus === 'ok'
            && $requiredActions === [];
    }

    /**
     * @return array{status:string,reason:string,required_action:string}
     */
    public static function nonConsumableRefusal(): array
    {
        return [
            'status' => 'fail',
            'reason' => self::NON_CONSUMABLE_REASON,
            'required_action' => self::NON_CONSUMABLE_REQUIRED_ACTION,
        ];
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
