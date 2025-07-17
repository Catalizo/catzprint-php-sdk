<?php

namespace Tests\Api;

use CatzPrint\Api\ApiClient;
use CatzPrint\Exceptions\ApiConnectionException;
use CatzPrint\Exceptions\PrintingException;
use PHPUnit\Framework\TestCase;

class ApiClientTest extends TestCase
{
    private $apiKey = '';

    public function testValidRequestReturnsData()
    {
        $client = new ApiClient($this->apiKey);
        $response = $client->sendRequest('/print-job', 'POST', [
            'source' => 'php',
            'content' => ['test' => 'test'],
            'orderId' => 'test'
        ]);
        $this->assertArrayHasKey('data', $response);
    }
}