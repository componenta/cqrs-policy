<?php

declare(strict_types=1);

namespace Componenta\CQRS\Policy\Transport;

use Closure;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\JsonCommandSerializer;
use Componenta\CQRS\Command\Transport\TransportException;
use Componenta\Identity\Uuid;
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\ActorInterface;
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
 * JSON serializer that persists ActorAware command actors as UUID references.
 *
 * Non-actor-aware commands are delegated to the standard strict serializer.
 */
final readonly class ActorAwareJsonCommandSerializer implements CommandSerializerInterface
{
    private const string FORMAT_KEY = '__componenta_cqrs';
    private const string DATA_KEY = 'data';
    private const string ACTOR_FIELD = 'actor';

    public function __construct(
        private ActorRepositoryInterface $actors,
        private CommandSerializerInterface $fallback = new JsonCommandSerializer(),
    ) {}

    public function serialize(object $command): string
    {
        if (!$command instanceof ActorAwareInterface) {
            return $this->fallback->serialize($command);
        }

        $reflection = new ReflectionClass($command);
        $data = $this->extractConstructorData($reflection, $command);

        try {
            return json_encode([
                self::FORMAT_KEY => JsonCommandSerializer::FORMAT_VERSION,
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
        if (!class_exists($commandClass)) {
            return $this->fallback->deserialize($payload, $commandClass);
        }

        if (!is_a($commandClass, ActorAwareInterface::class, true)) {
            return $this->fallback->deserialize($payload, $commandClass);
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

        $arguments = [];
        $remaining = $data;

        foreach ($parameters as $name => $parameter) {
            if (array_key_exists($name, $data)) {
                $value = $name === self::ACTOR_FIELD
                    ? $this->restoreActor($data[$name], $commandClass)
                    : $data[$name];

                $this->assertParameterType($value, $parameter, $commandClass);
                $arguments[] = $value;
                unset($remaining[$name]);
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            throw new TransportException("Missing required parameter '{$name}' for {$commandClass}.");
        }

        if ($remaining !== []) {
            throw new TransportException(sprintf(
                'Payload for %s contains unknown field(s): %s.',
                $commandClass,
                implode(', ', array_keys($remaining)),
            ));
        }

        $command = $this->instantiate($reflection, $arguments);

        if (!$command instanceof ActorAwareInterface) {
            throw new TransportException(sprintf(
                'Restored command %s no longer implements %s.',
                $commandClass,
                ActorAwareInterface::class,
            ));
        }

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
                if (!$value instanceof ActorInterface) {
                    throw new TransportException(sprintf(
                        'Actor-aware command property "%s::$%s" must implement %s; %s given.',
                        $reflection->getName(),
                        $name,
                        ActorInterface::class,
                        get_debug_type($value),
                    ));
                }

                $data[$name] = $value->uuid->toString();
                continue;
            }

            $this->assertJsonValue($value, $name);
            $data[$name] = $value;
        }

        return $data;
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

    private function restoreActor(mixed $value, string $commandClass): ActorInterface
    {
        if (!is_string($value)) {
            throw new TransportException(sprintf(
                'Serialized actor reference for %s must be a UUID string; %s given.',
                $commandClass,
                get_debug_type($value),
            ));
        }

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

        if (!$actor->uuid->equals($uuid)) {
            throw new TransportException(sprintf(
                'Actor repository returned a different identity for UUID "%s".',
                $uuid->toString(),
            ));
        }

        return $actor;
    }

    private function assertJsonValue(mixed $value, string $path): void
    {
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
                $this->assertJsonValue($item, $path . '.' . $key);
            }

            return;
        }

        throw new TransportException(sprintf(
            'Command field "%s" contains unsupported value of type %s; configure a custom serializer.',
            $path,
            get_debug_type($value),
        ));
    }

    /** @return array<string, mixed> */
    private function payloadData(mixed $decoded): array
    {
        $decoded = $this->jsonObject($decoded, 'Invalid payload: expected a JSON object.');

        if (!array_key_exists(self::FORMAT_KEY, $decoded)) {
            return $decoded;
        }

        if (($decoded[self::FORMAT_KEY] ?? null) !== JsonCommandSerializer::FORMAT_VERSION) {
            throw new TransportException(sprintf(
                'Unsupported command payload version "%s".',
                is_scalar($decoded[self::FORMAT_KEY] ?? null)
                    ? (string) $decoded[self::FORMAT_KEY]
                    : get_debug_type($decoded[self::FORMAT_KEY] ?? null),
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
