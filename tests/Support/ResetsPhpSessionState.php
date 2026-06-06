<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Support;

trait ResetsPhpSessionState
{
    private string $handlerBefore;
    private string|false $pathBefore;

    protected function captureSessionIniState(): void
    {
        $this->handlerBefore = (string) ini_get('session.save_handler');
        $this->pathBefore = ini_get('session.save_path');
    }

    protected function resetPhpSessionState(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    protected function restoreSessionIniState(): void
    {
        ini_set('session.save_handler', $this->handlerBefore);
        if ($this->pathBefore !== false) {
            ini_set('session.save_path', (string) $this->pathBefore);
        }
    }
}
