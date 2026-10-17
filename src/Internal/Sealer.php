<?php

declare(strict_types=1);

namespace CatzPrint\Internal;

use CatzPrint\Exception\CatzPrintException;

/**
 * NaCl sealed boxes (libsodium crypto_box_seal: X25519 + XSalsa20-Poly1305),
 * as accepted by the API's sealed_content field.
 *
 * @internal
 */
final class Sealer
{
    public static function available(): bool
    {
        return function_exists('sodium_crypto_box_seal');
    }

    /**
     * Seals $plaintext to the base64 (standard alphabet) X25519 public key and
     * returns the sealed box, base64-encoded.
     */
    public static function seal(string $plaintext, string $publicKeyBase64): string
    {
        if (!self::available()) {
            throw new CatzPrintException(
                'Sealed printing needs the PHP sodium extension (ext-sodium). Install or enable it, or print without "sealed".',
                0,
                'sodium_missing',
            );
        }
        $pk = base64_decode($publicKeyBase64, true);
        if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new CatzPrintException('The server public key is not 32 bytes of base64.', 0, 'bad_server_key');
        }

        return base64_encode(sodium_crypto_box_seal($plaintext, $pk));
    }
}
