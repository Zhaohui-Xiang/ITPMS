<?php

namespace App\Enums;

enum RequirementStatus: int
{
    case PENDING_REVIEW = 1;
    case ASSIGNED = 2;
    case IN_DEVELOPMENT = 3;
    case IN_TESTING = 4;
    case PENDING_DEPLOY = 5;
    case DEPLOYED = 6;
    case ACCEPTED = 7;

    public function label(): string
    {
        return match($this) {
            self::PENDING_REVIEW => '待审核',
            self::ASSIGNED => '已分配',
            self::IN_DEVELOPMENT => '开发中',
            self::IN_TESTING => '测试中',
            self::PENDING_DEPLOY => '待上线',
            self::DEPLOYED => '已上线',
            self::ACCEPTED => '已验收',
        };
    }
}
