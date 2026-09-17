<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application\Payload;

final class PayloadException extends \InvalidArgumentException
{
    public function __construct(public readonly string $problemCode, string $message)
    {
        parent::__construct($message);
    }
}
