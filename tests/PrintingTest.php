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
        $orderIds = [];
        for ($i = 1; $i <= 10; $i++) {
            $orderIds[] = 'ORDER-ID-' . $i;
        }

        foreach ($orderIds as $orderId) {
            $this->runPrintTest($orderId);
        }
    }

    private function runPrintTest(string $orderId)
    {
        $client = new ApiClient($this->apiKey);
        $printing = Printing::newPrintTask($client);

        $receipt = (new ReceiptPrinter)
            ->setTextSize(2, 2)
            ->centerAlign()
            ->text('BUSSINESS NAME')
            ->centerAlign()
            ->text('branch name')
            ->centerAlign()
            ->text('Token : ' . '123456')
            ->centerAlign()
            ->line()
            ->centerAlign()
            ->text('Booked Time: ' . '12-Jan-2025')
            ->centerAlign()
            ->line()
            ->text('Customer Name' . ' ' . 'Last Name')
            ->leftAlign()
            ->text('Address Near' . ', ' . 'Area')
            ->leftAlign()
            ->text('City Name')
            ->leftAlign()
            ->text('+123456789')
            ->line()
            ->feed(1);

        $order = ['variations' => []];
        for ($i = 1; $i <= 3; $i++) {
            $order['variations'][] = [
                'name' => $i === 1 ? 'basic' : 'extra' . $i,
                'product' => ['name' => "MANGO CHICKEN [FREE BUTTER NAAN + RICE] $i"],
                'pivot' => [
                    'qty' => $i,
                    'price' => 2.5 * $i,
                    'spicy_spec' => 'Mild',
                    'note' => $i % 2 === 0 ? "Note for item $i" : '',
                ]
            ];
        }

        foreach ($order['variations'] as $item) {
            $sub_item = ($item['name'] !== 'basic') ? '(' . $item['name'] . ')' : '';
            $spicy = '(' . $item['pivot']['spicy_spec'] . ')';
            $left = '--- Qty : ' . $item['pivot']['qty'];

            $receipt->text($item['product']['name'])
                ->text($sub_item . '-' . $spicy);

            if (!empty($item['pivot']['note'])) {
                $receipt->text('Note: ' . $item['pivot']['note']);
            }

            $receipt->twoColumnText(
                $left,
                'NZ$' . $item['pivot']['qty'] * $item['pivot']['price']
            );
        }

        $receipt->line()
            ->twoColumnText('Subtotal', 'NZ$23.99')
            ->twoColumnText('Delivery', 'NZ$20.99')
            ->twoColumnText('Tax', 'NZ$0.99')
            ->twoColumnText('Discount', 'NZ$0')
            ->line()
            ->twoColumnText('Total', 'NZ$40.99')
            ->line()
            ->twoColumnText('Payment', 'PAID')
            ->twoColumnText('Delivery Type', 'DELIVERY')
            ->feed(1)
            ->centerAlign()
            ->line()
            ->text('THANK YOU!')
            ->centerAlign()
            ->text('VISIT AGAIN!')
            ->centerAlign()
            ->text('https://example.com')
            ->centerAlign()
            ->cut();

        $printing
            ->content((string)$receipt)
            ->orderId($orderId);

        $jobId = $printing->send();
        $this->assertIsString($jobId);
    }
}