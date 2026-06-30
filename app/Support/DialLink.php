<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

/**
 * Renders the click-to-dial `<a>` markup used in every applicant phone
 * column. The real number NEVER appears in the rendered HTML — the visible
 * text is always masked (e.g. ••••••789) and the only machine-readable copy
 * is an encrypted token that the xplosip widget hands back to
 * DialLockController, which decrypts it server-side at dial time.
 */
class DialLink
{
    public static function render(?string $number, string $label): string
    {
        $number = trim((string) $number);
        if ($number === '') {
            return '';
        }

        $display = e(PhoneNumber::mask($number));
        $token   = e(Crypt::encryptString($number));
        $xplabel = e($label . ' ' . PhoneNumber::mask($number));

        return "<strong title=\"{$label}\">" . substr($label, 0, 1) . ':</strong> '
            . "<a href=\"javascript:void(0)\" "
            . "data-xpdial=\"{$token}\" data-xplabel=\"{$xplabel}\" "
            . "onclick=\"if(window.xplosipDial){xplosipDial(this);}\" "
            . "class=\"text-primary text-decoration-none\" "
            . "title=\"Click to dial\">{$display}</a>";
    }

    public static function resolve(?string $token): ?string
    {
        if (!is_string($token) || $token === '') {
            return null;
        }

        try {
            return Crypt::decryptString($token);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
