<?php

namespace App\Services\Permissions;

/**
 * 停机验证探针（v1.8 §2.10）：恢复命令仅允许在维护窗口执行。
 * 默认实现要求：维护页标记存在（artisan down）且 FPM/queue/scheduler 均已停止。
 * 测试通过容器绑定替身。
 */
class SystemDownProbe
{
    public function isDown(): bool
    {
        if (! file_exists(storage_path('framework/down'))) {
            return false;
        }

        foreach (['php-fpm', 'queue:work', 'queue:listen', 'schedule:work'] as $pattern) {
            $output = [];
            $code = 0;
            exec('pgrep -f '.escapeshellarg($pattern).' 2>/dev/null', $output, $code);
            if ($code === 0 && $output !== []) {
                return false;
            }
        }

        return true;
    }
}
