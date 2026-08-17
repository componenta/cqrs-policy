<?php

declare(strict_types=1);

use Componenta\CQRS\Resolver\ActorResolver;
use Componenta\CQRS\Tests\Fixture\FakeActor;
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\Guest;

final readonly class ActorResolverAwareMessage implements ActorAwareInterface
{
    public function __construct(public object $actor) {}
}

final readonly class ActorResolverAnonymousMessage {}

it('extracts only actors explicitly carried by messages', function (): void {
    $actor = new FakeActor(1);
    $guest = new Guest();
    $resolver = new ActorResolver();

    expect($resolver->resolve(new ActorResolverAwareMessage($actor)))->toBe($actor)
        ->and($resolver->resolve(new ActorResolverAwareMessage($guest)))->toBe($guest)
        ->and($resolver->resolve(new ActorResolverAnonymousMessage()))->toBeNull();
});
