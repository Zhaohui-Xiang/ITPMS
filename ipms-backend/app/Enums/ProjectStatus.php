<?php

namespace App\Enums;

enum ProjectStatus: int
{
    case ACTIVE = 1;
    case MAINTENANCE = 2;
    case ARCHIVED = 3;

    public function label(): string
    {
        return match($this) {
            self::ACTIVE => '活跃',
            self::MAINTENANCE => '维护中',
            self::ARCHIVED => '归档',
        };
    }
}
