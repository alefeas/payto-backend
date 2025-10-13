<?php

namespace App\Exceptions;

use Exception;

class ForbiddenException extends Exception
{
    public function __construct($message = 'Forbidden', $code = 403)
    {
        parent::__construct($message, $code);
    }
}
