<?php

namespace App\ValueObjects;

use JsonSerializable;

final readonly class ReleaseGateResult implements JsonSerializable
{
    /**
     * @var list<array{code: string, label: string, passed: bool, blocking: bool, details: array<string, mixed>}>
     */
    public array $checks;

    /**
     * @var list<array{code: string, label: string, passed: bool, blocking: bool, details: array<string, mixed>}>
     */
    public array $blocking;

    public bool $passed;

    /**
     * @param  list<array{code: string, label: string, passed: bool, blocking: bool, details: array<string, mixed>}>  $checks
     */
    public function __construct(array $checks)
    {
        $this->checks = array_values($checks);
        $this->blocking = array_values(array_filter(
            $this->checks,
            fn (array $check): bool => $check['blocking'] && ! $check['passed'],
        ));
        $this->passed = $this->blocking === [];
    }

    /**
     * @return array{
     *     passed: bool,
     *     checks: list<array{code: string, label: string, passed: bool, blocking: bool, details: array<string, mixed>}>,
     *     blocking: list<array{code: string, label: string, passed: bool, blocking: bool, details: array<string, mixed>}>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'passed' => $this->passed,
            'checks' => $this->checks,
            'blocking' => $this->blocking,
        ];
    }
}
