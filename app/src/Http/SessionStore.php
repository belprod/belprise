<?php

declare(strict_types=1);

namespace Belprise\Http;

interface SessionStore
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** New session id, data kept (fixation protection after login). */
    public function regenerate(): void;

    /** Drop all data and the session itself. */
    public function destroy(): void;
}
