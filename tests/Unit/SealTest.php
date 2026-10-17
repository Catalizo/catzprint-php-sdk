<?php

declare(strict_types=1);

namespace CatzPrint\Tests\Unit;

use CatzPrint\Exception\CatzPrintException;
use CatzPrint\Internal\Sealer;
use CatzPrint\Tests\TestCase;

/**
 * Sealed-box compatibility: content sealed by the SDK must open with
 * libsodium's crypto_box_seal_open — checked in PHP and, independently, with
 * PyNaCl (the same primitive the Go server's x/crypto/nacl/box uses).
 */
final class SealTest extends TestCase
{
    protected function setUp(): void
    {
        if (!Sealer::available()) {
            $this->markTestSkipped('ext-sodium not installed');
        }
    }

    public function testRoundTripWithSodium(): void
    {
        $kp = sodium_crypto_box_keypair();
        $msg = "%PDF-1.4\n" . random_bytes(2048);
        $sealed = Sealer::seal($msg, base64_encode(sodium_crypto_box_publickey($kp)));
        $raw = base64_decode($sealed, true);
        $this->assertSame(strlen($msg) + SODIUM_CRYPTO_BOX_SEALBYTES, strlen((string) $raw));
        $this->assertSame($msg, sodium_crypto_box_seal_open((string) $raw, $kp));
        // Fresh ephemeral key every time.
        $this->assertNotSame($sealed, Sealer::seal($msg, base64_encode(sodium_crypto_box_publickey($kp))));
    }

    public function testDecryptsWithPyNaCl(): void
    {
        $python = trim((string) shell_exec('command -v python3 2>/dev/null'));
        if ($python === '' || trim((string) shell_exec(escapeshellarg($python) . ' -c "import nacl" 2>&1; echo $?')) !== '0') {
            $this->markTestSkipped('python3 with PyNaCl not available');
        }
        $kp = sodium_crypto_box_keypair();
        $pk = base64_encode(sodium_crypto_box_publickey($kp));
        $sk = base64_encode(sodium_crypto_box_secretkey($kp));
        $messages = ['Hello, receipt! Café € ✓', '', random_bytes(100_000)];
        $script = <<<'PY'
import base64, json, sys
from nacl.public import PrivateKey, SealedBox
req = json.load(sys.stdin)
box = SealedBox(PrivateKey(base64.b64decode(req["sk"])))
print(json.dumps([base64.b64encode(box.decrypt(base64.b64decode(s))).decode() for s in req["sealed"]]))
PY;
        $input = json_encode(['sk' => $sk, 'sealed' => array_map(static fn ($m) => Sealer::seal($m, $pk), $messages)]);
        $proc = proc_open([$python, '-I', '-c', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertTrue(is_resource($proc));
        fwrite($pipes[0], (string) $input);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($proc);
        $this->assertSame(0, $code, "python failed: $err");
        $decoded = array_map(static fn ($b) => base64_decode($b, true), json_decode((string) $out, true));
        $this->assertSame($messages, $decoded);
    }

    public function testRejectsBadPublicKey(): void
    {
        try {
            Sealer::seal('x', base64_encode('short'));
            $this->fail('expected exception');
        } catch (CatzPrintException $e) {
            $this->assertSame('bad_server_key', $e->errorCode);
        }
    }
}
