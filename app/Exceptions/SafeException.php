<?php

namespace App\Exceptions;

use Illuminate\Database\QueryException;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * A database or mail error with the personal data taken out, so it can go to
 * logs and LoggerAI. Laravel puts the SQL with its values in a QueryException's
 * message ("Duplicate entry 'ada@…'"), and mail errors name the recipient. The
 * type of error, the table and the stack trace stay; the values don't.
 */
class SafeException extends RuntimeException
{
    public static function fromQuery(QueryException $e): self
    {
        $sql = ltrim($e->getSql());
        $verb = strtoupper(strtok($sql, " \n\t(") ?: 'QUERY');
        $table = preg_match('/\b(?:from|into|update|table)\s+[`"]?([A-Za-z0-9_]+)/i', $sql, $m) ? $m[1] : 'unknown';
        $state = $e->errorInfo[0] ?? $e->getCode();
        $driverCode = $e->errorInfo[1] ?? null;

        return self::copy($e, "Database error {$state}".($driverCode ? " ({$driverCode})" : '')." on {$verb} {$table}");
    }

    public static function fromMail(TransportExceptionInterface&Throwable $e): self
    {
        return self::copy($e, 'Mail delivery failed: '.self::scrub($e->getMessage()));
    }

    /** Removes emails, phone numbers, NHC IDs and long digit runs from free text. */
    public static function scrub(string $text): string
    {
        $text = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $text) ?? $text;
        $text = preg_replace('/NHC-\d+(-\d+)?/i', '[nhc_id]', $text) ?? $text;

        return preg_replace('/\+?\d[\d\s-]{6,}\d/', '[number]', $text) ?? $text;
    }

    /** Same place and stack trace as the original, new message, no link back to it. */
    private static function copy(Throwable $original, string $message): self
    {
        $safe = new self(mb_substr($message, 0, 500), (int) $original->getCode());

        foreach (['file' => $original->getFile(), 'line' => $original->getLine()] as $name => $value) {
            (new ReflectionProperty(\Exception::class, $name))->setValue($safe, $value);
        }
        (new ReflectionProperty(\Exception::class, 'trace'))->setValue($safe, $original->getTrace());

        return $safe;
    }
}
