<?php

namespace App\Enums;

enum DefectSeverity: int
{
    case FATAL = 1;
    case SERIOUS = 2;
    case NORMAL = 3;
    case MINOR = 4;

    public function label(): string
    {
        return match($this) {
            self::FATAL => '致命(P0)',
            self::SERIOUS => '严重(P1)',
            self::NORMAL => '一般(P2)',
            self::MINOR => '轻微(P3)',
        };
    }
}
