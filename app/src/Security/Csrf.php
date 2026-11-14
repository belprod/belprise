<?php

declare(strict_types=1);

namespace Belprise\Security;

use Belprise\Http\Request;
use Belprise\Http\SessionStore;

/**
 * Synchronizer token kept in the session and sent back by the front end in
 * the X-CSRF-Token header on every state-changing request.
 */
final class Csrf
{
    public const HEADER = 'X-CSRF-Token';
    private const SESSION_KEY = 'csrf';

    public function __construct(private readonly SessionStore $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = $this->rotate();
        }

        return $token;
    }

    public function rotate(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    public function isValid(Request $request): bool
    {
        $expected = $this->session->get(self::SESSION_KEY);
        $given = $request->header(self::HEADER);

        return is_string($expected) && $expected !== '' && is_string($given) && hash_equals($expected, $given);
    }
}
