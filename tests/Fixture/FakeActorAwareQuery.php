<?php

declare(strict_types=1);

namespace Componenta\CQRS\Tests\Fixture;

use Componenta\Policy\Actor\ActorAwareInterface;

final readonly class FakeActorAwareQuery implements ActorAwareInterface
{
    public function __construct(public object $actor) {}
}
