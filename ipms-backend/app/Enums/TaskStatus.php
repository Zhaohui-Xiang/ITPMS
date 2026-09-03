<?php

namespace App\Enums;

enum TaskStatus: int
{
    case TODO = 1;
    case IN_PROGRESS = 2;
    case COMPLETED = 3;
    case SUSPENDED = 4;

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::TODO => [self::IN_PROGRESS, self::SUSPENDED],
            self::IN_PROGRESS => [self::COMPLETED, self::SUSPENDED],
            self::SUSPENDED => [self::TODO, self::IN_PROGRESS],
            self::COMPLETED => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::TODO => '待开始',
            self::IN_PROGRESS => '进行中',
            self::COMPLETED => '已完成',
            self::SUSPENDED => '已挂起',
        };
    }
}
