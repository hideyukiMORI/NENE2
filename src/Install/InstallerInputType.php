<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * The input type of an {@see InstallerField}, backing the HTML `type` attribute the neutral
 * {@see InstallerRenderer} emits. Kept to the minimum a wizard needs: free text and secrets.
 *
 * The backing values are safe literal attribute values (`text` / `password`) chosen by the
 * product, never operator input, so the renderer can emit them without escaping. Additional
 * cases (e.g. a future `select`) can be added without breaking the default-`Text` contract.
 */
enum InstallerInputType: string
{
    case Text = 'text';
    case Password = 'password';

    /**
     * Whether a submitted value for this type may be reflected back into the rendered form.
     * Secrets are never echoed, so an operator's password can't leak into page source.
     */
    public function reflectsValue(): bool
    {
        return $this !== self::Password;
    }
}
