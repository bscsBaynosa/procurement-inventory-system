<?php

namespace App\Services;

class PasswordService
{
    /** Generate a random password of given length using safe characters. */
    public static function randomPassword(int $length = 8): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $n = strlen($chars);
        $bytes = random_bytes($length);
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[ord($bytes[$i]) % $n];
        }
        return $out;
    }
}
