<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class SmsFailed extends HttpException
{
    public function __construct()
    {
        parent::__construct(503, "We couldn't send the text message. Please try again.");
    }
}
