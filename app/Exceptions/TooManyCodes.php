<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class TooManyCodes extends HttpException
{
    public function __construct(int $retryAfter)
    {
        parent::__construct(429, 'Too many codes. Try again shortly.', null, ['Retry-After' => $retryAfter]);
    }
}
