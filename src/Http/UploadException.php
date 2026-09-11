<?php

declare(strict_types=1);

namespace Framework\Http;

// A rejected upload, carrying the user-facing message plus the HTTP status the controller should
// answer with. Thrown by UploadGuard; a controller catches it and turns it into a JSON error, so
// the message is written to be shown to whoever uploaded the file.
class UploadException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}
