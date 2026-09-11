<?php
// Run only for the disposable demo database after migrations.
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.env') !== 'demo' || config('database.connections.pgsql.database') !== 'itpms_demo') {
    throw new LogicException('Refusing to seed outside the demo database.');
}
$class = App\Models\Role::query()->exists()
    ? Database\Seeders\DemoWorkflowSeeder::class
    : Database\Seeders\DatabaseSeeder::class;
exit(Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => $class, '--force' => true]));
