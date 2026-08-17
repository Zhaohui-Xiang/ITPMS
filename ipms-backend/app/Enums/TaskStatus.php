<?php

namespace App\Enums;

enum TaskStatus: int
{
    case TODO = 1;
    case IN_PROGRESS = 2;
    case COMPLETED = 3;
    case SUSPENDED = 4;

    public function label(): string
    {
        return match($this) {
            self::TODO => '待开始',
            self::IN_PROGRESS => '进行中',
            self::COMPLETED => '已完成',
            self::SUSPENDED => '已挂起',
        };
    }
}
