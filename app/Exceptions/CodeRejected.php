<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** One message for every failure, so a guess learns nothing. */
class CodeRejected extends HttpException
{
    public function __construct(string $message = 'That code is wrong or has expired.')
    {
        parent::__construct(422, $message);
    }
}
