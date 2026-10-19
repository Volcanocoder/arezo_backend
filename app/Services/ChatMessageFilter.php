<?php

namespace App\Services;

/**
 * Basic server-side moderation for direct chat messages.
 * This is a practical first-pass filter, not a guarantee against every obfuscation.
 */
class ChatMessageFilter
{
    /** Return a Persian rejection reason, or null if the message passes. */
    public function rejectionReason(string $message): ?string
    {
        $text = $this->normalize($message);

        if ($this->containsPhoneNumber($text)) {
            return 'ارسال شماره تماس در گفتگو مجاز نیست. لطفاً بدون اطلاعات تماس پیام بده.';
        }

        if ($this->containsEmailOrLink($text)) {
            return 'ارسال ایمیل، لینک یا شناسه شبکه‌های اجتماعی در گفتگو مجاز نیست.';
        }

        if ($this->containsBankDetails($text)) {
            return 'ارسال شماره کارت، شبا یا اطلاعات حساب بانکی در گفتگو مجاز نیست.';
        }

        if ($this->containsProfanity($text)) {
            return 'پیام به‌دلیل داشتن واژه توهین‌آمیز ارسال نشد. لطفاً محترمانه‌تر بنویس.';
        }

        return null;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'أ' => 'ا', 'إ' => 'ا',
            'ؤ' => 'و', 'ئ' => 'ی', 'ۀ' => 'ه', 'ة' => 'ه',
            "\u{0640}" => '', "\u{200c}" => ' ', "\u{200d}" => '',
            "\u{200e}" => '', "\u{200f}" => '',
        ]);

        // Remove Arabic vowel marks and normalize repeated whitespace.
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

        return $text;
    }

    private function containsPhoneNumber(string $text): bool
    {
        // Iranian mobile numbers in domestic or international form, allowing common separators.
        $sep = '[\s().\-‐‑–—]*';
        $patterns = [
            '/(?<!\d)0' . $sep . '9(?:' . $sep . '\d){9}(?!\d)/u',
            '/(?<!\d)(?:\+?98|0098)' . $sep . '9(?:' . $sep . '\d){9}(?!\d)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function containsEmailOrLink(string $text): bool
    {
        if (preg_match('/[a-z0-9._%+\-]+\s*@\s*[a-z0-9.\-]+\s*\.\s*[a-z]{2,}/iu', $text) === 1) {
            return true;
        }

        if (preg_match('/(?:https?:\/\/|www\.|t\.me\/|telegram\.me\/|wa\.me\/|instagram\.com\/|eitaa\.com\/|rubika\.ir\/)/iu', $text) === 1) {
            return true;
        }

        // Social handles often function as off-platform contact details.
        return preg_match('/(?<![\p{L}\p{N}])@[a-z0-9_.]{3,}/iu', $text) === 1;
    }

    private function containsBankDetails(string $text): bool
    {
        // Iranian IBAN / Sheba: IR followed by 24 digits, allowing spaces or hyphens.
        if (preg_match('/(?<![a-z0-9])ir[\s\-]*(?:\d[\s\-]*){24}(?!\d)/iu', $text) === 1) {
            return true;
        }

        // Bank/account keywords accompanied by a numeric identifier.
        $bankContext = '(?:شماره\s*(?:حساب|کارت|شبا)|شماره\s*کارت\s*بانکی|کارت\s*بانکی|حساب\s*بانکی|شماره\s*حساب|شبا|کارت\s*به\s*کارت|شماره\s*کارت)';
        if (preg_match('/' . $bankContext . '[^\n\r]{0,45}(?:\d[\d\s\-]{5,30}\d)/u', $text) === 1) {
            return true;
        }

        // A 16-digit card-like number (separators allowed). Check Luhn to reduce false positives.
        if (preg_match_all('/(?<!\d)(?:\d[\s\-]*){16}(?!\d)/u', $text, $matches)) {
            foreach ($matches[0] as $candidate) {
                $digits = preg_replace('/\D/u', '', $candidate) ?? '';
                if (strlen($digits) === 16 && $this->passesLuhn($digits)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }

    private function containsProfanity(string $text): bool
    {
        // Starting set of common insults / profanity. Extend carefully after testing false positives.
        $terms = [
            'بی\s*شعور',
            'بی\s*ادب',
            'احمق',
            'عوضی',
            'کثافت',
            'آشغال',
            'حرومزاده',
            'حرامزاده',
            'مادر\s*جنده',
            'پدرسوخته',
            'کس\s*کش',
            'کص\s*کش',
            'کونکش',
            'کس\s*شر',
            'کص\s*شر',
            'جنده',
            'کیر',
            'کون',
            'گوه',
            'کص',
            'خفه\s*شو',
        ];

        foreach ($terms as $term) {
            if (preg_match('/(?<!\p{L})(?:' . $term . ')(?!\p{L})/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
