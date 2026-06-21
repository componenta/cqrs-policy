<?php

declare(strict_types=1);

namespace Componenta\CQRS\Tests\Fixture;

final readonly class FakeQuery
{
    public function __construct(public string $tag = 'plain') {}
}
