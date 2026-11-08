<?php

namespace App\Exceptions;

use RuntimeException;

/** Galat verifikasi login sosial; membawa kode HTTP + pesan yang aman ditampilkan ke pengguna. */
class SocialAuthException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 401)
    {
        parent::__construct($message);
    }
}
