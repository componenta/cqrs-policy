<?php

declare(strict_types=1);

namespace Componenta\CQRS\Query\Exception;

use RuntimeException;

/** Thrown when query policy evaluation has no actor to evaluate. */
final class AuthenticationRequiredException extends RuntimeException
{
    public function __construct(
        public readonly string $actionId,
        string $details = '',
    ) {
        $message = "Authentication required for action '{$actionId}'";

        if ($details !== '') {
            $message .= ": {$details}";
        }

        parent::__construct($message);
    }
}
