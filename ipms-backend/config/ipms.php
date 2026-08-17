<?php

use App\Support\StrictBoolean;

return [
    'seed_demo' => StrictBoolean::parse(env('IPMS_SEED_DEMO', false)),
    'allow_production_demo_seed' => StrictBoolean::parse(env('IPMS_ALLOW_PRODUCTION_DEMO_SEED', false)),
    'demo_password' => env('IPMS_DEMO_PASSWORD'),
];
