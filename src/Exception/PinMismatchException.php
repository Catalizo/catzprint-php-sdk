<?php

declare(strict_types=1);

namespace CatzPrint\Exception;

/**
 * A station presented a certificate whose SHA-256 fingerprint (of the
 * certificate DER) or, for a public hostname, whose public key (SPKI) hash
 * is not the pinned one. The connection was closed before any request byte
 * was sent. It counts as "station unreachable": you find it in
 * StationUnreachableException::$attempts.
 */
class PinMismatchException extends ConnectionException
{
    /**
     * @param string $want Pinned lower-case hex SHA-256.
     * @param string $got  What the station presented.
     * @param bool   $spki True when the public-key pin of a public hostname failed.
     */
    public function __construct(
        public readonly string $want,
        public readonly string $got,
        public readonly bool $spki = false,
    ) {
        parent::__construct(
            sprintf(
                'The station\'s %s %s doesn\'t match the pinned %s.',
                $spki ? 'public key (SPKI) fingerprint' : 'certificate fingerprint',
                $got,
                $want,
            ),
            0,
            'pin_mismatch',
        );
    }
}
