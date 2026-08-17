<?php

namespace Tests\Unit\Support;

use App\Support\ApiResponse;
use Tests\TestCase;

final class ApiResponseTest extends TestCase
{
    public function test_success_wraps_payload(): void
    {
        $response = ApiResponse::success(['id' => 7], 'created', 201);

        $this->assertSame(201, $response->status());
        $this->assertSame([
            'code' => 201,
            'message' => 'created',
            'data' => ['id' => 7],
        ], $response->getData(true));
    }

    public function test_error_includes_machine_code_and_trace_id(): void
    {
        $response = ApiResponse::error('STALE_VERSION', '数据已更新', 409, ['lock_version' => 4], 'trace-1');

        $this->assertSame('STALE_VERSION', $response->getData(true)['error_code']);
        $this->assertSame('trace-1', $response->getData(true)['trace_id']);
    }
}
