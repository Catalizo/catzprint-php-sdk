<?php

declare(strict_types=1);

namespace CatzPrint\Tests\Unit;

use CatzPrint\Exception\AuthenticationException;
use CatzPrint\Exception\CatzPrintException;
use CatzPrint\Exception\ConflictException;
use CatzPrint\Exception\NotFoundException;
use CatzPrint\Exception\OutOfPrintsException;
use CatzPrint\Exception\PermissionException;
use CatzPrint\Exception\RateLimitException;
use CatzPrint\Exception\ServerException;
use CatzPrint\Exception\ValidationException;
use CatzPrint\Internal\ErrorFactory;
use CatzPrint\Internal\Response;
use CatzPrint\Tests\TestCase;

final class ErrorMappingTest extends TestCase
{
    /** @param array<string, string> $headers */
    private static function res(int $status, mixed $error, array $headers = []): Response
    {
        $body = is_string($error) ? $error : (string) json_encode(['error' => $error]);

        return new Response($status, $headers, $body);
    }

    public function testStatusesMapToClasses(): void
    {
        $cases = [
            [401, 'invalid_api_key', AuthenticationException::class],
            [403, 'forbidden', PermissionException::class],
            [404, 'not_found', NotFoundException::class],
            [400, 'invalid', ValidationException::class],
            [422, 'no_printer', ValidationException::class],
            [402, 'out_of_prints', OutOfPrintsException::class],
            [409, 'invalid_state', ConflictException::class],
            [429, 'rate_limited', RateLimitException::class],
            [500, 'internal', ServerException::class],
            [503, 'unavailable', ServerException::class],
            [410, 'content_deleted', CatzPrintException::class],
        ];
        foreach ($cases as [$status, $code, $class]) {
            $e = ErrorFactory::fromResponse(self::res($status, ['code' => $code, 'message' => "msg $code"]));
            $this->assertSame($class, get_class($e), "status $status");
            $this->assertSame($status, $e->status);
            $this->assertSame($code, $e->errorCode);
            $this->assertSame("msg $code", $e->getMessage());
            $this->assertInstanceOf(CatzPrintException::class, $e);
        }
    }

    public function testOutOfPrintsCodeWinsOverStatus(): void
    {
        $e = ErrorFactory::fromResponse(self::res(403, ['code' => 'out_of_prints', 'message' => 'No prints left.']));
        $this->assertInstanceOf(OutOfPrintsException::class, $e);
    }

    public function testValidationFields(): void
    {
        $e = ErrorFactory::fromResponse(self::res(400, [
            'code' => 'invalid',
            'message' => 'Unknown printer.',
            'fields' => ['printer_id' => 'Unknown printer.'],
        ]));
        $this->assertInstanceOf(ValidationException::class, $e);
        $this->assertSame(['printer_id' => 'Unknown printer.'], $e->fields);
        $this->assertSame(['printer_id' => 'Unknown printer.'], $e->getFields());
    }

    public function testRetryAfterFromBodyAndHeader(): void
    {
        $e = ErrorFactory::fromResponse(self::res(429, ['code' => 'rate_limited', 'message' => 'Slow down.', 'retry_after_seconds' => 7]));
        $this->assertInstanceOf(RateLimitException::class, $e);
        $this->assertSame(7, $e->retryAfter);

        $e = ErrorFactory::fromResponse(self::res(429, ['code' => 'rate_limited', 'message' => 'Slow down.'], ['retry-after' => '12']));
        $this->assertSame(12, $e->getRetryAfter());

        $this->assertNull(ErrorFactory::parseRetryAfter(null));
        $this->assertNull(ErrorFactory::parseRetryAfter('soon'));
        $this->assertGreaterThanOrEqual(0, ErrorFactory::parseRetryAfter(gmdate('D, d M Y H:i:s', time() + 30) . ' GMT'));
    }

    public function testNonJsonBodyGetsGenericMessage(): void
    {
        $e = ErrorFactory::fromResponse(self::res(502, '<html>Bad Gateway</html>', ['x-request-id' => 'req_123']));
        $this->assertInstanceOf(ServerException::class, $e);
        $this->assertSame('', $e->errorCode);
        $this->assertStringContainsString('502', $e->getMessage());
        $this->assertSame('req_123', $e->requestId);
    }
}
