<?php

declare(strict_types=1);

namespace Componenta\CQRS\Tests\Fixture;

use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\ActorInterface;
use Componenta\Policy\Policies\PermissionPolicy;

#[PermissionPolicy(new GuardedPermission('posts.view.any'))]
final readonly class GuardedQueryWithPermission implements ActorAwareInterface
{
    public function __construct(public ActorInterface $actor) {}
}
