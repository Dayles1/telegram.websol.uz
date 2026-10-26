<?php

namespace App\Exceptions;

use Exception;

class ApiException extends Exception
{
    protected int $status;

    public function __construct(string $message, int $status = 403)
    {
        parent::__construct($message);
        $this->status = $status;
    }

    public function status(): int
    {
        return $this->status;
    }
}
