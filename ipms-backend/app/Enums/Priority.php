<?php

namespace App\Enums;

enum Priority: int
{
    case URGENT = 1;
    case HIGH = 2;
    case MEDIUM = 3;
    case LOW = 4;

    public function label(): string
    {
        return match($this) {
            self::URGENT => '紧急',
            self::HIGH => '高',
            self::MEDIUM => '中',
            self::LOW => '低',
        };
    }
}
