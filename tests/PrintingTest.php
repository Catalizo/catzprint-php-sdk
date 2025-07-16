<?php

namespace Tests\Api;

use CatzPrint\Api\ApiClient;
use CatzPrint\Api\Printing;
use CatzPrint\Exceptions\PrintingException;
use CatzPrint\Printing\ReceiptPrinter;
use PHPUnit\Framework\TestCase;

class PrintingTest extends TestCase
{
    private $apiKey = '';

    public function testSuccessfulPrintReturnsJobId()
    {
        $client = new ApiClient($this->apiKey);

        $printing = Printing::newPrintTask($client);

        $receipt = (new ReceiptPrinter)
            ->setTextSize(2, 2)
            ->centerAlign()
            ->text('Company Name')
            ->centerAlign()
            ->text('order')
            ->centerAlign()
            ->text('Token : ' . 'value')
            ->centerAlign()
            ->line()
            ->centerAlign()
            ->text('Booked Time: ' . 'time')
            ->centerAlign()
            ->line()
            ->feed(1);

        $printing
            ->userId('')
            ->content((string) $receipt)
            ->orderId('ORDER-456');

        $jobId = $printing->send();
        $this->assertIsString($jobId);
    }
}