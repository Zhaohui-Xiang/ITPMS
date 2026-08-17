<?php

namespace App\Support;

use LogicException;

final class DemoSeedGuard
{
    public static function assertProductionAuthorized(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        if (config('ipms.seed_demo') !== true || config('ipms.allow_production_demo_seed') !== true) {
            throw new LogicException(
                'Production seeding requires IPMS_SEED_DEMO=true and IPMS_ALLOW_PRODUCTION_DEMO_SEED=true.',
            );
        }
    }
}
