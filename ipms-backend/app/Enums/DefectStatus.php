<?php

namespace App\Enums;

enum DefectStatus: int
{
    case PENDING_CONFIRM = 1;
    case CONFIRMED = 2;
    case FIXING = 3;
    case PENDING_RETEST = 4;
    case CLOSED = 5;
    case REOPENED = 6;

    public function canConfirm(): bool
    {
        return $this === self::PENDING_CONFIRM;
    }

    public function canAssign(): bool
    {
        return in_array($this, [self::CONFIRMED, self::REOPENED], true);
    }

    public function canResolve(): bool
    {
        return $this === self::FIXING;
    }

    public function canVerify(): bool
    {
        return $this === self::PENDING_RETEST;
    }

    public function canReopen(): bool
    {
        return $this === self::CLOSED;
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING_CONFIRM => '待确认',
            self::CONFIRMED => '已确认',
            self::FIXING => '修复中',
            self::PENDING_RETEST => '待复测',
            self::CLOSED => '已关闭',
            self::REOPENED => '重新打开',
        };
    }
}
