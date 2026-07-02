<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * The machine contract for an installer: its step {@see InstallerFlow}, the
 * {@see InstallerMessages} that turn reason codes into wording, and the
 * {@see ReInstallationGuard} evaluated at the entry of every step.
 *
 * A product implements this to declare its own flow and branding; the toolkit ships no
 * default template because the right steps differ per product (the lesson from not baking
 * a default vocabulary elsewhere). The neutral {@see InstallerRenderer} consumes a
 * template to produce unbranded HTML.
 */
interface InstallerTemplate
{
    public function flow(): InstallerFlow;

    public function messages(): InstallerMessages;

    public function guard(): ReInstallationGuard;
}
