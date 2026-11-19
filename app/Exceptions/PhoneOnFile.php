<?php

namespace App\Exceptions;

use RuntimeException;

/** The number belongs to someone on file, so a person has to review it. Nothing merges. */
class PhoneOnFile extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This number is on file for another patient and needs manual review.');
    }
}
