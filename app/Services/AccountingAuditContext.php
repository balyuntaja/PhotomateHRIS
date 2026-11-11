<?php

namespace App\Services;

use App\Models\Karyawan;

class AccountingAuditContext
{
    private static ?string $reason = null;

    private static ?string $actorId = null;

    public static function run(?Karyawan $actor, ?string $reason, callable $callback): mixed
    {
        $previousReason = self::$reason;
        $previousActorId = self::$actorId;

        self::$reason = filled($reason) ? trim((string) $reason) : null;
        self::$actorId = $actor?->karyawan_id;

        try {
            return $callback();
        } finally {
            self::$reason = $previousReason;
            self::$actorId = $previousActorId;
        }
    }

    public static function reason(): ?string
    {
        return self::$reason;
    }

    public static function actorId(): ?string
    {
        return self::$actorId;
    }
}
