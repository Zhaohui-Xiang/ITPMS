<?php

namespace App\Enums;

enum ProjectDeliveryStatus: int
{
    case ASSIGNED = 2;
    case IN_DEVELOPMENT = 3;
    case IN_TESTING = 4;
    case PENDING_DEPLOY = 5;
    case DEPLOYED = 6;
    case ACCEPTED = 7;

    /**
     * @return list<self>
     */
    public function allowedForwardTransitions(): array
    {
        return match ($this) {
            self::ASSIGNED => [self::IN_DEVELOPMENT],
            self::IN_DEVELOPMENT => [self::IN_TESTING],
            self::IN_TESTING => [self::PENDING_DEPLOY],
            self::PENDING_DEPLOY => [self::DEPLOYED],
            self::DEPLOYED => [self::ACCEPTED],
            self::ACCEPTED => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ASSIGNED => '已分配',
            self::IN_DEVELOPMENT => '开发中',
            self::IN_TESTING => '测试中',
            self::PENDING_DEPLOY => '待上线',
            self::DEPLOYED => '已上线',
            self::ACCEPTED => '已验收',
        };
    }
}
