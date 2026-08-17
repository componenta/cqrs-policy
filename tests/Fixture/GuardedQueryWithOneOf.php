<?php

declare(strict_types=1);

namespace Componenta\CQRS\Tests\Fixture;

use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Attribute\OneOf;
use Componenta\Policy\Policies\PermissionPolicy;

#[OneOf(
    new PermissionPolicy(new GuardedPermission('posts.view.any')),
    new PermissionPolicy(new GuardedPermission('posts.edit.any')),
)]
final readonly class GuardedQueryWithOneOf implements ActorAwareInterface
{
    public function __construct(public object $actor) {}
}
