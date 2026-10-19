<?php

namespace App\Helpers;

final class JobFailure extends \RuntimeException
{
    public function __construct(string $message, int $code = 500)
    {
        parent::__construct($message, $code);
    }
}
