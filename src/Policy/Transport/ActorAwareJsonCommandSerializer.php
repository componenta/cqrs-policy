<?php

declare(strict_types=1);

namespace Componenta\CQRS\Policy\Transport;

use Closure;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\CommandSerializerSupportInterface;
use Componenta\CQRS\Command\Transport\TransportException;
use Componenta\Identity\IdentityInterface;
use Componenta\Identity\Uuid;
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\Guest;
use JsonException;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

/**
 * JSON serializer for commands that explicitly carry a policy actor.
 *
 * The standard actor reference model is intentionally small:
 * - {@see Guest} is encoded as a stateless tagged reference;
 * - {@see IdentityInterface} is encoded by UUID and restored through
 *   {@see ActorRepositoryInterface};
 * - application-specific actor kinds belong to an application serializer that
 *   is ordered before this serializer in a composite.
 */
final readonly class ActorAwareJsonCommandSerializer implements CommandSerializerInterface, CommandSerializerSupportInterface
{
    private const int FORMAT_VERSION = 2;
    private const int MAX_NESTING_DEPTH = 64;

    private const string FORMAT_KEY = '__componenta_cqrs';
    private const string DATA_KEY = 'data';
    private const string ACTOR_FIELD = 'actor';
    private const string ACTOR_TYPE_FIELD = 'type';
    private const string ACTOR_UUID_FIELD = 'uuid';
    private const string ACTOR_TYPE_GUEST = 'guest';
    private const string ACTOR_TYPE_IDENTITY = 'identity';

    public function __construct(private ActorRepositoryInterface $actors) {}

    public function supportsCommand(object|string $command): bool
    {
        $class = is_object($command) ? $command::class : $command;

        return is_a($class, ActorAwareInterface::class, true);
    }

    public function serialize(object $command): string
    {
        if (!$this->supportsCommand($command)) {
            throw new TransportException(sprintf(
                '%s does not support command %s.',
                self::class,
                $command::class,
            ));
        }

        $reflection = new ReflectionClass($command);
        $data = $this->extractConstructorData($reflection, $command);

        try {
            return json_encode([
                self::FORMAT_KEY => self::FORMAT_VERSION,
                self::DATA_KEY => $data,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new TransportException(
                "Failed to serialize command: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }

    public function deserialize(string $payload, string $commandClass): object
    {
        if (!$this->supportsCommand($commandClass)) {
            throw new TransportException(sprintf(
                '%s does not support command class %s.',
                self::class,
                $commandClass,
            ));
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new TransportException(
                "Failed to deserialize command: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        $data = $this->payloadData($decoded);
        $reflection = new ReflectionClass($commandClass);

        if (!$reflection->isInstantiable()) {
            throw new TransportException("Command class '{$commandClass}' must be instantiable.");
        }

        $parameters = $this->constructorParameters($reflection);
        $properties = $this->assertSupportedProperties($reflection, $parameters);
        $this->assertActorShape($reflection, $parameters, $properties);

        if (!array_key_exists(self::ACTOR_FIELD, $data)) {
            throw new TransportException(sprintf(
                'Transported actor-aware command %s is missing its actor reference.',
                $commandClass,
            ));
        }

        $unknownFields = array_values(array_diff(array_keys($data), array_keys($parameters)));
        if ($unknownFields !== []) {
            throw new TransportException(sprintf(
                'Payload for %s contains unknown field(s): %s.',
                $commandClass,
                implode(', ', $unknownFields),
            ));
        }

        /** @var list<mixed> $arguments */
        $arguments = [];
        /** @var array<string, mixed> $expectedState */
        $expectedState = [];
        $restoredActor = null;

        foreach ($parameters as $name => $parameter) {
            if (array_key_exists($name, $data)) {
                if ($name === self::ACTOR_FIELD) {
                    $restoredActor = $this->restoreActor($data[$name], $commandClass);
                    $value = $restoredActor;
                } else {
                    $value = $data[$name];
                }

                $this->assertParameterType($value, $parameter, $commandClass);
                $arguments[] = $value;
                $expectedState[$name] = $value;
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $value = $parameter->getDefaultValue();
                $this->assertJsonValue($value, $name);
                $arguments[] = $value;
                $expectedState[$name] = $value;
                continue;
            }

            throw new TransportException("Missing required parameter '{$name}' for {$commandClass}.");
        }

        $command = $this->instantiate($reflection, $arguments);

        if (!$command instanceof ActorAwareInterface || $restoredActor === null) {
            throw new TransportException(sprintf(
                'Restored command %s no longer satisfies its actor-aware contract.',
                $commandClass,
            ));
        }

        if ($command->actor !== $restoredActor) {
            throw new TransportException(sprintf(
                'Restored command %s replaced the actor instance restored from transport.',
                $commandClass,
            ));
        }

        $this->assertRoundTripState($command, $expectedState, $properties, $commandClass);

        return $command;
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return array<string, mixed>
     */
    private function extractConstructorData(ReflectionClass $reflection, object $command): array
    {
        $parameters = $this->constructorParameters($reflection);
        $properties = $this->assertSupportedProperties($reflection, $parameters);
        $this->assertActorShape($reflection, $parameters, $properties);
        $data = [];

        foreach ($properties as $name => $property) {
            if (!$property->isInitialized($command)) {
                throw new TransportException("Command property '{$name}' is not initialized.");
            }

            try {
                $value = $property->getValue($command);
            } catch (Throwable $exception) {
                throw new TransportException(
                    "Cannot read command property '{$name}': {$exception->getMessage()}",
                    previous: $exception,
                );
            }

            if ($name === self::ACTOR_FIELD) {
                if (!is_object($value)) {
                    throw new TransportException(sprintf(
                        'Actor-aware command property "%s::$%s" must be an object; %s given.',
                        $reflection->getName(),
                        $name,
                        get_debug_type($value),
                    ));
                }

                $data[$name] = $this->serializeActor($value, $reflection->getName());
                continue;
            }

            $this->assertJsonValue($value, $name);
            $data[$name] = $value;
        }

        return $data;
    }

    /** @return array{type: 'guest'}|array{type: 'identity', uuid: string} */
    private function serializeActor(object $actor, string $commandClass): array
    {
        if ($actor instanceof Guest) {
            return [self::ACTOR_TYPE_FIELD => self::ACTOR_TYPE_GUEST];
        }

        if ($actor instanceof IdentityInterface) {
            return [
                self::ACTOR_TYPE_FIELD => self::ACTOR_TYPE_IDENTITY,
                self::ACTOR_UUID_FIELD => $actor->uuid->toString(),
            ];
        }

        throw new TransportException(sprintf(
            'Actor-aware command %s carries unsupported actor %s. The standard serializer supports %s or %s; register an application-specific serializer before it for additional actor kinds.',
            $commandClass,
            $actor::class,
            Guest::class,
            IdentityInterface::class,
        ));
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return array<string, ReflectionParameter>
     */
    private function constructorParameters(ReflectionClass $reflection): array
    {
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $parameters = [];

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isVariadic() || $parameter->isPassedByReference()) {
                throw new TransportException(sprintf(
                    'Actor-aware JSON serialization does not support variadic or by-reference constructor parameter "%s" on %s.',
                    $parameter->getName(),
                    $reflection->getName(),
                ));
            }

            $this->assertSupportedParameterType($parameter, $reflection);
            $parameters[$parameter->getName()] = $parameter;
        }

        return $parameters;
    }

    /** @param ReflectionClass<object> $reflection */
    private function assertSupportedParameterType(
        ReflectionParameter $parameter,
        ReflectionClass $reflection,
    ): void {
        $type = $parameter->getType();

        if ($type === null || !self::containsExecutableType($type)) {
            return;
        }

        throw new TransportException(sprintf(
            'Actor-aware JSON serialization does not support executable callable constructor parameter "%s" on %s.',
            $parameter->getName(),
            $reflection->getName(),
        ));
    }

    private static function containsExecutableType(ReflectionType $type): bool
    {
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if (self::containsExecutableType($member)) {
                    return true;
                }
            }

            return false;
        }

        if (!$type instanceof ReflectionNamedType) {
            return false;
        }

        if ($type->isBuiltin()) {
            return $type->getName() === 'callable';
        }

        return is_a($type->getName(), Closure::class, true);
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param array<string, ReflectionParameter> $parameters
     * @return array<string, ReflectionProperty>
     */
    private function assertSupportedProperties(ReflectionClass $reflection, array $parameters): array
    {
        $properties = [];

        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $name = $property->getName();

            if (!array_key_exists($name, $parameters)) {
                throw new TransportException(sprintf(
                    'Command property "%s::$%s" is not represented by a constructor parameter.',
                    $reflection->getName(),
                    $name,
                ));
            }

            if (!$property->isPublic()) {
                throw new TransportException(sprintf(
                    'Actor-aware JSON serialization requires constructor-backed property "%s::$%s" to be public.',
                    $reflection->getName(),
                    $name,
                ));
            }

            if ($property->isVirtual() || $property->getHooks() !== []) {
                throw new TransportException(sprintf(
                    'Actor-aware JSON serialization does not support hooked or virtual property "%s::$%s".',
                    $reflection->getName(),
                    $name,
                ));
            }

            $properties[$name] = $property;
        }

        foreach (array_keys($parameters) as $name) {
            if (!isset($properties[$name])) {
                throw new TransportException(sprintf(
                    'Constructor parameter "%s::$%s" must have a matching public stored property for actor-aware JSON serialization.',
                    $reflection->getName(),
                    $name,
                ));
            }
        }

        return $properties;
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param array<string, ReflectionParameter> $parameters
     * @param array<string, ReflectionProperty> $properties
     */
    private function assertActorShape(
        ReflectionClass $reflection,
        array $parameters,
        array $properties,
    ): void {
        if (!isset($parameters[self::ACTOR_FIELD], $properties[self::ACTOR_FIELD])) {
            throw new TransportException(sprintf(
                'Actor-aware command %s must expose actor through a constructor-backed public stored "$actor" property.',
                $reflection->getName(),
            ));
        }
    }

    private function restoreActor(mixed $value, string $commandClass): object
    {
        $reference = $this->jsonObject(
            $value,
            sprintf(
                'Serialized actor reference for %s must be a tagged JSON object.',
                $commandClass,
            ),
        );

        $type = $reference[self::ACTOR_TYPE_FIELD] ?? null;

        if ($type === self::ACTOR_TYPE_GUEST) {
            if ($reference !== [self::ACTOR_TYPE_FIELD => self::ACTOR_TYPE_GUEST]) {
                throw new TransportException(sprintf(
                    'Guest actor reference for %s must contain only the "%s" discriminator.',
                    $commandClass,
                    self::ACTOR_TYPE_FIELD,
                ));
            }

            return new Guest();
        }

        if ($type === self::ACTOR_TYPE_IDENTITY) {
            if (array_keys($reference) !== [self::ACTOR_TYPE_FIELD, self::ACTOR_UUID_FIELD]
                && array_keys($reference) !== [self::ACTOR_UUID_FIELD, self::ACTOR_TYPE_FIELD]
            ) {
                throw new TransportException(sprintf(
                    'Identity actor reference for %s must contain exactly "%s" and "%s".',
                    $commandClass,
                    self::ACTOR_TYPE_FIELD,
                    self::ACTOR_UUID_FIELD,
                ));
            }

            $uuid = $reference[self::ACTOR_UUID_FIELD];
            if (!is_string($uuid)) {
                throw new TransportException(sprintf(
                    'Identity actor UUID for %s must be a string; %s given.',
                    $commandClass,
                    get_debug_type($uuid),
                ));
            }

            return $this->restoreIdentityActor($uuid, $commandClass);
        }

        throw new TransportException(sprintf(
            'Unsupported actor reference type "%s" for %s.',
            is_scalar($type) ? (string) $type : get_debug_type($type),
            $commandClass,
        ));
    }

    private function restoreIdentityActor(string $value, string $commandClass): object
    {
        try {
            $uuid = Uuid::fromString($value);
        } catch (Throwable $exception) {
            throw new TransportException(sprintf(
                'Serialized actor reference "%s" for %s is not a valid UUID.',
                $value,
                $commandClass,
            ), previous: $exception);
        }

        $actor = $this->actors->findByUuid($uuid);

        if ($actor === null) {
            throw new TransportException(sprintf(
                'Actor "%s" required by transported command %s was not found.',
                $uuid->toString(),
                $commandClass,
            ));
        }

        if (!$actor instanceof IdentityInterface) {
            throw new TransportException(sprintf(
                'Actor repository returned non-identifiable actor %s for UUID "%s"; %s is required.',
                $actor::class,
                $uuid->toString(),
                IdentityInterface::class,
            ));
        }

        if (!$actor->uuid->equals($uuid)) {
            throw new TransportException(sprintf(
                'Actor repository returned a different identity for UUID "%s".',
                $uuid->toString(),
            ));
        }

        return $actor;
    }

    private function assertJsonValue(mixed $value, string $path, int $depth = 0): void
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            throw new TransportException(sprintf(
                'Command field "%s" exceeds the maximum JSON nesting depth of %d.',
                $path,
                self::MAX_NESTING_DEPTH,
            ));
        }

        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new TransportException("Command field '{$path}' must contain a finite float.");
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->assertJsonValue($item, $path . '.' . $key, $depth + 1);
            }

            return;
        }

        throw new TransportException(sprintf(
            'Command field "%s" contains unsupported value of type %s; register a custom serializer.',
            $path,
            get_debug_type($value),
        ));
    }

    /**
     * @param array<string, mixed> $expectedState
     * @param array<string, ReflectionProperty> $properties
     */
    private function assertRoundTripState(
        object $command,
        array $expectedState,
        array $properties,
        string $commandClass,
    ): void {
        foreach ($expectedState as $name => $expected) {
            $property = $properties[$name];

            if (!$property->isInitialized($command)) {
                throw new TransportException(sprintf(
                    'Restored command %s left constructor-backed field "%s" uninitialized.',
                    $commandClass,
                    $name,
                ));
            }

            try {
                $actual = $property->getValue($command);
            } catch (Throwable $exception) {
                throw new TransportException(
                    sprintf('Cannot read restored command field "%s": %s', $name, $exception->getMessage()),
                    previous: $exception,
                );
            }

            if (!$this->valuesEquivalent($expected, $actual)) {
                throw new TransportException(sprintf(
                    'Restored command %s changed constructor-backed field "%s" during reconstruction.',
                    $commandClass,
                    $name,
                ));
            }
        }
    }

    private function valuesEquivalent(mixed $expected, mixed $actual, int $depth = 0): bool
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            return false;
        }

        if (is_int($expected) && is_float($actual)) {
            return (float) $expected === $actual;
        }

        if (is_float($expected) && is_int($actual)) {
            return $expected === (float) $actual;
        }

        if (is_array($expected) && is_array($actual)) {
            if (array_keys($expected) !== array_keys($actual)) {
                return false;
            }

            foreach ($expected as $key => $value) {
                if (!$this->valuesEquivalent($value, $actual[$key], $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $expected === $actual;
    }

    /** @return array<string, mixed> */
    private function payloadData(mixed $decoded): array
    {
        $decoded = $this->jsonObject($decoded, 'Invalid payload: expected a JSON object.');

        if (!array_key_exists(self::FORMAT_KEY, $decoded)) {
            throw new TransportException('Actor-aware command payload must use the versioned envelope.');
        }

        $version = $decoded[self::FORMAT_KEY];
        if ($version !== self::FORMAT_VERSION) {
            throw new TransportException(sprintf(
                'Unsupported command payload version "%s".',
                is_scalar($version) ? (string) $version : get_debug_type($version),
            ));
        }

        if (count($decoded) !== 2 || !array_key_exists(self::DATA_KEY, $decoded)) {
            throw new TransportException('Invalid versioned command payload envelope.');
        }

        return $this->jsonObject(
            $decoded[self::DATA_KEY],
            'Invalid versioned command payload envelope.',
        );
    }

    /** @return array<string, mixed> */
    private function jsonObject(mixed $value, string $error): array
    {
        if (!is_array($value)) {
            throw new TransportException($error);
        }

        $object = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new TransportException($error);
            }

            $object[$key] = $item;
        }

        return $object;
    }

    private function assertParameterType(
        mixed $value,
        ReflectionParameter $parameter,
        string $commandClass,
    ): void {
        $type = $parameter->getType();

        if ($type === null || $this->matchesType($value, $type)) {
            return;
        }

        throw new TransportException(sprintf(
            'Payload field "%s" for %s must match %s; %s given.',
            $parameter->getName(),
            $commandClass,
            (string) $type,
            get_debug_type($value),
        ));
    }

    private function matchesType(mixed $value, ReflectionType $type): bool
    {
        if ($value === null && $type->allowsNull()) {
            return true;
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if ($this->matchesType($value, $member)) {
                    return true;
                }
            }

            return false;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if (!$this->matchesType($value, $member)) {
                    return false;
                }
            }

            return true;
        }

        assert($type instanceof ReflectionNamedType);

        return match ($type->getName()) {
            'mixed' => true,
            'null' => $value === null,
            'bool' => is_bool($value),
            'true' => $value === true,
            'false' => $value === false,
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'string' => is_string($value),
            'array' => is_array($value),
            'iterable' => is_iterable($value),
            'object' => is_object($value),
            'callable' => false,
            default => is_object($value) && is_a($value, $type->getName()),
        };
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param list<mixed> $arguments
     */
    private function instantiate(ReflectionClass $reflection, array $arguments): object
    {
        try {
            return $reflection->newInstanceArgs($arguments);
        } catch (Throwable $exception) {
            throw new TransportException(sprintf(
                'Failed to instantiate command %s: %s',
                $reflection->getName(),
                $exception->getMessage(),
            ), previous: $exception);
        }
    }
}
