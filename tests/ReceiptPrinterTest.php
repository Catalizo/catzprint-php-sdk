<?php

namespace Tests\Printing;

use CatzPrint\Printing\ReceiptPrinter;
use PHPUnit\Framework\TestCase;

class ReceiptPrinterTest extends TestCase
{
    private $printer;

    protected function setUp(): void
    {
        $this->printer = new ReceiptPrinter();
    }

    public function testInvalidTextSizeThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->printer->setTextSize(0, 5);
    }

    public function testEmptyTextThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->printer->text('   ');
    }

    public function testCommandChainProducesValidJson()
    {
        $json = $this->printer
            ->centerAlign()
            ->text('Hello World')
            ->feed(2)
            ->cut()
            ->getJson();

        $expected = <<<JSON
[
    {
        "action": "align",
        "value": "center"
    },
    {
        "action": "text",
        "content": "Hello World"
    },
    {
        "action": "feed",
        "lines": 2
    },
    {
        "action": "cut"
    }
]
JSON;

        $this->assertJsonStringEqualsJsonString($expected, $json);
    }

    public function testTwoColumnTextFormat()
    {
        $this->printer->twoColumnText('Item', '$10.00');
        $commands = $this->printer->getCommands();
        
        $this->assertEquals('twoColumnText', $commands[0]['action']);
        $this->assertEquals('Item', $commands[0]['left']);
        $this->assertEquals('$10.00', $commands[0]['right']);
    }
}