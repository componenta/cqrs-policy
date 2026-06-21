<?php

declare(strict_types=1);

namespace Componenta\CQRS\Tests\Fixture;

use Psr\Container\ContainerInterface;

final class FakeContainer implements ContainerInterface
{
    /** @param array<string, mixed> $entries */
    public function __construct(private readonly array $entries = []) {}

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new class("Not found: $id") extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {};
        }
        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}
