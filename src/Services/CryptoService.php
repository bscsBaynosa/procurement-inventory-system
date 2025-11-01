<?php

namespace App\Services;

/**
 * Lightweight field-level encryption helper.
 * - Uses libsodium XChaCha20-Poly1305 if available; falls back to OpenSSL AES-256-GCM.
 * - Envelope format: v1:<backend>:<nonce|iv>:<ciphertext>[:<tag>], all base64url encoded.
 * - Key comes from APP_DATA_KEY (env). If missing, passthrough (returns plaintext) for safety.
 */
class CryptoService
{
    private static ?string $key = null; // 32-byte binary
    private const VERSION = 'v1';

    private static function loadKey(): ?string
    {
        if (self::$key !== null) { return self::$key; }
        $pass = getenv('APP_DATA_KEY') ?: ($_ENV['APP_DATA_KEY'] ?? '');
        if ($pass === '') { self::$key = null; return null; }
        // Derive a fixed-length key using sodium (preferred) or hash as fallback
        if (function_exists('sodium_crypto_pwhash')) {
            $salt = getenv('APP_DATA_SALT') ?: ($_ENV['APP_DATA_SALT'] ?? 'app-data-salt-default-1234');
            $saltBin = substr(hash('sha256', $salt, true), 0, SODIUM_CRYPTO_PWHASH_SALTBYTES);
            self::$key = sodium_crypto_pwhash(
                SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
                $pass,
                $saltBin,
                SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
                SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
                SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
            );
        } else {
            self::$key = substr(hash('sha256', 'app-data-key:' . $pass, true), 0, 32);
        }
        return self::$key;
    }

    public static function encrypt(?string $plaintext, string $aad = ''): ?string
    {
        if ($plaintext === null) { return null; }
        $key = self::loadKey();
        if ($key === null) { return $plaintext; } // passthrough if no key
        // Prefer sodium
        if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ct = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $key);
            return implode(':', [
                self::VERSION,
                'sodium',
                rtrim(strtr(base64_encode($nonce), '+/', '-_'), '='),
                rtrim(strtr(base64_encode($ct), '+/', '-_'), '='),
            ]);
        }
        // Fallback to OpenSSL AES-256-GCM
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        return implode(':', [
            self::VERSION,
            'openssl',
            rtrim(strtr(base64_encode($iv), '+/', '-_'), '='),
            rtrim(strtr(base64_encode($ct), '+/', '-_'), '='),
            rtrim(strtr(base64_encode($tag), '+/', '-_'), '='),
        ]);
    }

    public static function decrypt(?string $ciphertext, string $aad = ''): ?string
    {
        if ($ciphertext === null) { return null; }
        $parts = explode(':', (string)$ciphertext);
        if (count($parts) < 3 || $parts[0] !== self::VERSION) { return $ciphertext; }
        $key = self::loadKey();
        if ($key === null) { return $ciphertext; }
        $backend = $parts[1];
        if ($backend === 'sodium' && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
            $nonce = base64_decode(strtr($parts[2], '-_', '+/'));
            $ct = base64_decode(strtr($parts[3] ?? '', '-_', '+/'));
            $pt = @sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ct, $aad, $nonce, $key);
            return $pt === false ? null : $pt;
        }
        if ($backend === 'openssl') {
            $iv = base64_decode(strtr($parts[2], '-_', '+/'));
            $ct = base64_decode(strtr($parts[3] ?? '', '-_', '+/'));
            $tag = base64_decode(strtr($parts[4] ?? '', '-_', '+/'));
            $pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
            return $pt === false ? null : $pt;
        }
        return $ciphertext; // unknown backend/version
    }

    public static function maybeDecrypt(?string $value, string $aad = ''): ?string
    {
        if ($value === null) { return null; }
        $parts = explode(':', (string)$value, 2);
        if (($parts[0] ?? '') === self::VERSION) { return self::decrypt($value, $aad); }
        return $value;
    }
}
