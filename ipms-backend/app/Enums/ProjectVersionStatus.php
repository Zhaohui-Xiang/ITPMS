<?php

namespace App\Enums;

enum ProjectVersionStatus: int
{
    case DRAFT = 1;
    case PLANNED = 2;
    case IN_DEVELOPMENT = 3;
    case IN_TESTING = 4;
    case READY_TO_RELEASE = 5;
    case RELEASED = 6;
    case ARCHIVED = 7;

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DRAFT => [self::PLANNED],
            self::PLANNED => [self::DRAFT, self::IN_DEVELOPMENT],
            self::IN_DEVELOPMENT => [self::PLANNED, self::IN_TESTING],
            self::IN_TESTING => [self::IN_DEVELOPMENT, self::READY_TO_RELEASE],
            self::READY_TO_RELEASE => [self::IN_TESTING, self::RELEASED],
            self::RELEASED => [self::ARCHIVED],
            self::ARCHIVED => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => '草稿',
            self::PLANNED => '已计划',
            self::IN_DEVELOPMENT => '开发中',
            self::IN_TESTING => '测试中',
            self::READY_TO_RELEASE => '待发布',
            self::RELEASED => '已发布',
            self::ARCHIVED => '已归档',
        };
    }
}
