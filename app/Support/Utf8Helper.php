<?php

namespace App\Support;

class Utf8Helper
{
    public static function clean(?string $str): string
    {
        if (blank($str)) {
            return '';
        }

        // Самый надёжный способ — подавляем ошибку iconv
        $result = @iconv('UTF-8', 'UTF-8//IGNORE', $str);
        $result = $result ?: '';

        // Убираем символ замены U+FFFD (валидный, но мусорный)
        $result = preg_replace('/\x{FFFD}/u', '', $result) ?? $result;

        return trim($result);
    }
}
