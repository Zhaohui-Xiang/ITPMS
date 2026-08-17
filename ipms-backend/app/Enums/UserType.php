<?php

namespace App\Enums;

enum UserType: int
{
    case INTERNAL = 1;
    case SUPPLIER = 2;
    case SYSTEM_USER = 3;

    public function label(): string
    {
        return match($this) {
            self::INTERNAL => '内部IT',
            self::SUPPLIER => '供应商',
            self::SYSTEM_USER => '系统用户',
        };
    }
}
