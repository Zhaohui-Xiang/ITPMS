<?php

namespace Tests\Feature\Api;

use App\Exceptions\DomainConflictException;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Tests\TestCase;

class DomainConflictExceptionContractTest extends TestCase
{
    public function test_constructor_accepts_stable_error_code_and_status_contract(): void
    {
        $exception = new DomainConflictException('STALE_VERSION', 409);

        $this->assertSame('STALE_VERSION', $exception->errorCode);
        $this->assertSame(409, $exception->status);
        $this->assertSame([], $exception->errors);
        $this->assertSame('STALE_VERSION', $exception->getMessage());
        $this->assertTrue((new ReflectionClass(DomainConflictException::class))->isFinal());
    }

    public function test_renderer_uses_exception_status_errors_and_default_message(): void
    {
        Route::get('/api/test-domain-conflict-contract', function (): never {
            throw new DomainConflictException(
                'INVALID_WORKFLOW_FIELDS',
                422,
                [
                    'version' => ['The version is stale.'],
                    'status' => ['The status cannot move backward.'],
                ],
            );
        });

        $this->getJson('/api/test-domain-conflict-contract')
            ->assertUnprocessable()
            ->assertJsonPath('code', 422)
            ->assertJsonPath('error_code', 'INVALID_WORKFLOW_FIELDS')
            ->assertJsonPath('message', 'INVALID_WORKFLOW_FIELDS')
            ->assertJsonPath('errors.version.0', 'The version is stale.')
            ->assertJsonPath(
                'errors.status.0',
                'The status cannot move backward.',
            );
    }
}
