<?php

declare(strict_types=1);

namespace CatzPrint\Tests\Unit;

use CatzPrint\Client;
use CatzPrint\Model\Job;
use CatzPrint\Model\Key;
use CatzPrint\Resource\Jobs;
use CatzPrint\Tests\TestCase;

final class ClientTest extends TestCase
{
    private const SECRET = 'cz_live_SuperSecretValue_DoNotLeak_9xYz';

    public function testRequiresApiKey(): void
    {
        $prev = getenv('CATZPRINT_API_KEY');
        putenv('CATZPRINT_API_KEY');
        try {
            $this->expectException(\InvalidArgumentException::class);
            new Client();
        } finally {
            if ($prev !== false) {
                putenv('CATZPRINT_API_KEY=' . $prev);
            }
        }
    }

    public function testReadsKeyAndBaseUrlFromEnv(): void
    {
        $prevKey = getenv('CATZPRINT_API_KEY');
        $prevUrl = getenv('CATZPRINT_BASE_URL');
        putenv('CATZPRINT_API_KEY=cz_test_fromEnvironment12345');
        putenv('CATZPRINT_BASE_URL=https://example.test/');
        try {
            $c = new Client();
            $this->assertFalse($c->isLive());
            $this->assertSame('https://example.test', $c->http()->baseUrl());
        } finally {
            putenv($prevKey === false ? 'CATZPRINT_API_KEY' : 'CATZPRINT_API_KEY=' . $prevKey);
            putenv($prevUrl === false ? 'CATZPRINT_BASE_URL' : 'CATZPRINT_BASE_URL=' . $prevUrl);
        }
        $this->assertSame(Client::DEFAULT_BASE_URL, (new Client('cz_test_x'))->http()->baseUrl());
    }

    public function testBaseUrlMustBeHttpsExceptLocalhost(): void
    {
        foreach (['http://api-v1.catzprint.com', 'http://10.0.0.5:8080', 'ftp://x.test', 'not a url', 'https://u:p@x.test', 'https://x.test/?a=1'] as $bad) {
            $threw = false;
            try {
                new Client('cz_test_x', ['baseUrl' => $bad]);
            } catch (\InvalidArgumentException) {
                $threw = true;
            }
            $this->assertTrue($threw, "accepted $bad");
        }
        foreach (['https://api.example.test', 'http://localhost:8080', 'http://127.0.0.1:1', 'http://[::1]:3000'] as $ok) {
            $this->assertSame($ok, (new Client('cz_test_x', ['baseUrl' => $ok . '/']))->http()->baseUrl());
        }
    }

    public function testPrintHelpersDoNotReadArbitraryFilesOrWrappers(): void
    {
        $c = new Client('cz_test_x', ['baseUrl' => 'http://127.0.0.1:1', 'orgId' => 'o', 'maxRetries' => 0]);
        $tmp = tempnam(sys_get_temp_dir(), 'cz');
        file_put_contents($tmp, "root:x:0:0:secret\n");
        try {
            foreach (['printPdf', 'printImage'] as $fn) {
                $threw = false;
                try {
                    $c->{$fn}($tmp);
                } catch (\InvalidArgumentException $e) {
                    $threw = true;
                }
                $this->assertTrue($threw, "$fn read a non-matching file");
            }
        } finally {
            unlink($tmp);
        }
        // A stream wrapper is never opened: it is treated as content and sent as-is.
        $m = new \ReflectionMethod(Client::class, 'bytesOrFile');
        $this->assertSame(['php://filter/resource=/etc/passwd', null], $m->invoke(null, 'php://filter/resource=/etc/passwd', '%PDF-'));
    }

    public function testFileUrlsAreReadAsLocalPaths(): void
    {
        $m = new \ReflectionMethod(Client::class, 'bytesOrFile');
        $pdf = tempnam(sys_get_temp_dir(), 'cz') . '.pdf';
        file_put_contents($pdf, "%PDF-1.4\nhello\n%%EOF\n");
        $txt = tempnam(sys_get_temp_dir(), 'cz');
        file_put_contents($txt, "root:x:0:0:secret\n");
        try {
            $this->assertSame([file_get_contents($pdf), basename($pdf)], $m->invoke(null, 'file://' . $pdf, '%PDF-'));
            $this->assertSame([file_get_contents($pdf), basename($pdf)], $m->invoke(null, 'FILE://localhost' . $pdf, '%PDF-'));
            // The type check still applies to file:// URLs.
            $threw = false;
            try {
                $m->invoke(null, 'file://' . $txt, '%PDF-');
            } catch (\InvalidArgumentException) {
                $threw = true;
            }
            $this->assertTrue($threw, 'file:// read a non-PDF file');
            $threw = false;
            try {
                $m->invoke(null, 'file://' . $txt, null);
            } catch (\InvalidArgumentException) {
                $threw = true;
            }
            $this->assertTrue($threw, 'file:// read a non-image file');
            // Other wrappers, and file:// pointing back at a wrapper, are not opened.
            foreach (['phar://' . $pdf . '/x', 'file://phar://' . $pdf, 'file://otherhost' . $pdf] as $v) {
                $this->assertSame([$v, null], $m->invoke(null, $v, '%PDF-'));
            }
        } finally {
            unlink($pdf);
            unlink($txt);
            @unlink(substr($pdf, 0, -4));
        }
    }

    public function testRejectsUnknownOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('cz_test_x', ['base_url' => 'oops']);
    }

    public function testApiKeyIsRedactedEverywhere(): void
    {
        $c = new Client(self::SECRET, ['orgId' => 'org-1']);
        ob_start();
        var_dump($c);
        $dump = (string) ob_get_clean();
        $this->assertStringNotContainsString(self::SECRET, $dump);
        $this->assertStringNotContainsString(self::SECRET, print_r($c, true));
        $this->assertStringNotContainsString(self::SECRET, print_r($c->http(), true));
        $this->assertStringContainsString('cz_live_…9xYz', print_r($c, true));
        $this->assertSame('cz_test_…', Client::redact('cz_test_short'));
        $this->assertSame('…', Client::redact('something-else'));
        try {
            serialize($c);
            $this->fail('serialize should refuse');
        } catch (\LogicException $e) {
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
    }

    public function testModelsAreTypedAndReadOnly(): void
    {
        $job = Job::fromArray([
            'id' => 'j1', 'live' => true, 'status' => 'failed', 'status_detail' => 'Out of paper', 'copies' => 2,
            'printer_id' => 'p1', 'billed_as' => 'plan', 'created_at' => '2026-10-16T06:03:22.123456789Z',
            'printed_at' => null, 'future' => ['x' => 1],
        ]);
        $this->assertTrue($job->live);
        $this->assertTrue($job->isFinal());
        $this->assertFalse($job->isPrinted());
        $this->assertSame(2, $job->copies);
        $this->assertSame('p1', $job->printerId);
        $this->assertNull($job->printedAt);
        $this->assertNull($job->events);
        $this->assertSame('123456', $job->createdAt?->format('u'));
        $this->assertSame(['x' => 1], $job['future']);
        $this->assertTrue(isset($job->future));
        $this->assertSame('j1', json_decode((string) json_encode($job), true)['id']);
        try {
            $job['id'] = 'x';
            $this->fail('models are read-only');
        } catch (\LogicException) {
            $this->assertSame('j1', $job->id);
        }

        $key = new Key(['id' => 'k', 'org_id' => 'o', 'org_name' => 'Shop', 'live' => false, 'scopes' => ['jobs:read']]);
        $this->assertTrue($key->hasScope('jobs:read'));
        $this->assertFalse($key->hasScope('jobs:write'));
    }

    public function testDetectKind(): void
    {
        $this->assertSame('pdf', Jobs::detectKind("%PDF-1.7\n"));
        $this->assertSame('image', Jobs::detectKind("\x89PNG\r\n"));
        $this->assertSame('image', Jobs::detectKind("\xff\xd8\xff\xe0"));
        $this->assertSame('document', Jobs::detectKind('PK\x03\x04'));
    }
}
