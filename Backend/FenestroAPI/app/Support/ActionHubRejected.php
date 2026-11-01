<?php

namespace App\Support;

use RuntimeException;

/** An Action Hub Submit the rules refuse (stale version, status change not allowed). Nothing is saved. */
class ActionHubRejected extends RuntimeException
{
    /** @param string[] $fields form fields the message belongs to */
    public function __construct(string $message, public readonly int $httpStatus = 422, public readonly string $event = 'transition_rejected', public readonly array $fields = ['status'])
    {
        parent::__construct($message);
    }
}
