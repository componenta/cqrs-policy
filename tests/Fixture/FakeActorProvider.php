<?php

declare(strict_types=1);

namespace Componenta\CQRS\Tests\Fixture;

use Componenta\Policy\Actor\ActorProviderInterface;

final class FakeActorProvider implements ActorProviderInterface
{
    public function __construct(private ?object $actor = null) {}

    public function setActor(?object $actor): void
    {
        $this->actor = $actor;
    }

    public function getActor(): ?object
    {
        return $this->actor;
    }
}
