<?php

namespace App\Enums;

enum RequirementType: int
{
    case FEATURE = 1;
    case OPTIMIZATION = 2;
    case DATA = 3;
    case INTERFACE = 4;
    case OTHER = 5;

    public function label(): string
    {
        return match ($this) {
            self::FEATURE => '功能需求',
            self::OPTIMIZATION => '优化需求',
            self::DATA => '数据需求',
            self::INTERFACE => '接口需求',
            self::OTHER => '其他',
        };
    }
}
