<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * A neutral, unbranded reference renderer for an installer step.
 *
 * It carries no product name, logo, host preset or styling — only structural markup with
 * class hooks — so a product restyles it or replaces it wholesale. Every value it emits is
 * escaped ({@see Html}). It evaluates the template's {@see ReInstallationGuard} at the entry
 * of each step and, if installation is blocked, renders the blocked notice instead of the
 * form.
 *
 * Errors are supplied as reason codes and resolved through {@see InstallerMessages} — there
 * is deliberately no way to hand it a raw string, so an exception message (which may carry a
 * URL or other internal detail) can never be echoed to the page. Map failures to a reason
 * code; unknown codes fall back to a safe generic message.
 *
 * CSRF for the wizard POST is intentionally out of scope here and is reconciled with the
 * product's existing installer during its adoption.
 */
final readonly class InstallerRenderer
{
    /**
     * @param list<string>          $errorCodes Reason codes to display (never raw messages).
     * @param array<string, string> $values     Current values for the step's inputs, keyed by input name.
     */
    public function render(InstallerTemplate $template, string $stepId, array $errorCodes = [], array $values = []): string
    {
        $blockedReason = $template->guard()->blockedReason();

        if ($blockedReason !== null) {
            return $this->renderBlocked($template->messages(), $blockedReason);
        }

        $flow = $template->flow();
        $step = $flow->step($stepId);
        $messages = $template->messages();

        $html = '<section class="installer-step" data-step="' . Html::escape($step->id) . '">';
        $html .= '<p class="installer-progress">Step ' . $flow->position($stepId) . ' of ' . $flow->count() . '</p>';

        if ($errorCodes !== []) {
            $html .= '<ul class="installer-errors">';

            foreach ($errorCodes as $code) {
                $html .= '<li>' . Html::escape($messages->forReasonCode($code)) . '</li>';
            }

            $html .= '</ul>';
        }

        $html .= '<form method="post">';

        foreach ($step->inputs as $field) {
            $name = Html::escape($field->name);
            // The type comes from a product-controlled enum, never operator input,
            // so its literal value is safe to place in the attribute unescaped.
            $type = $field->type->value;

            // Secrets are never reflected: a submitted password must not reappear in page source.
            $valueAttr = $field->type->reflectsValue()
                ? ' value="' . Html::escape($values[$field->name] ?? '') . '"'
                : '';

            $html .= '<label class="installer-field">' . $name
                . '<input type="' . $type . '" name="' . $name . '"' . $valueAttr . '></label>';
        }

        $html .= '</form></section>';

        return $html;
    }

    private function renderBlocked(InstallerMessages $messages, string $reasonCode): string
    {
        return '<section class="installer-blocked"><p>'
            . Html::escape($messages->forReasonCode($reasonCode))
            . '</p></section>';
    }
}
