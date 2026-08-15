<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Exception;

use RuntimeException;

/**
 * Thrown when an action requires authentication but no actor is available.
 */
final class AuthenticationRequiredException extends RuntimeException
{
    /**
     * @param string $actionId The action that required authentication
     * @param string $details Additional details about why authentication failed
     */
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
