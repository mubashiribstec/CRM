<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

/**
 * Renders the click-to-dial `<a>` markup used in every applicant phone
 * column. The real number never appears in the HTML — only an encrypted,
 * single-use-looking token that the xplosip widget hands back to
 * DialLockController, which decrypts it server-side.
 */
class DialLink
{
    public static function render(?string $number, string $label, bool $reveal): string
    {
        $number = trim((string) $number);
        if ($number === '') {
            return '';
        }

        $display = $reveal ? e($number) : e(PhoneNumber::mask($number));
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
