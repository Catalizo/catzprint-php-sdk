<?php

declare(strict_types=1);

/*
 * Print a receipt from commands.
 *
 *   CATZPRINT_API_KEY=cz_test_… php examples/print_receipt.php
 *
 * Optional: CATZPRINT_PRINTER_ID (otherwise the best "receipt" printer is
 * chosen), CATZPRINT_BASE_URL.
 */

require __DIR__ . '/autoload.php';

use CatzPrint\Client;
use CatzPrint\Exception\CatzPrintException;

$catz = new Client(); // reads CATZPRINT_API_KEY

$order = ['number' => 'A-1042', 'items' => [['Flat white', 2, 4.50], ['Banana bread', 1, 5.50]]];

$commands = [
    ['type' => 'text', 'text' => 'CORNER CAFE', 'align' => 'center', 'size' => 2, 'bold' => true],
    ['type' => 'text', 'text' => '12 Main Street', 'align' => 'center', 'font' => 'b'],
    ['type' => 'line', 'char' => '='],
];
$total = 0.0;
foreach ($order['items'] as [$name, $qty, $price]) {
    $total += $qty * $price;
    $commands[] = ['type' => 'columns', 'columns' => [
        ['text' => "$qty x $name", 'width' => 0.7],
        ['text' => number_format($qty * $price, 2), 'align' => 'right'],
    ]];
}
array_push(
    $commands,
    ['type' => 'line'],
    ['type' => 'text', 'text' => 'TOTAL  ' . number_format($total, 2), 'align' => 'right', 'bold' => true, 'size' => 2],
    ['type' => 'feed', 'lines' => 1],
    ['type' => 'qr', 'data' => 'https://cornercafe.example/r/' . $order['number'], 'size' => 6, 'align' => 'center'],
    ['type' => 'cut', 'partial' => true],
);

$target = getenv('CATZPRINT_PRINTER_ID') ? ['printerId' => getenv('CATZPRINT_PRINTER_ID')] : ['role' => 'receipt'];

try {
    $job = $catz->printReceipt($commands, $target + [
        'title' => 'Order ' . $order['number'],
        'reference' => $order['number'],
        // Same key = same job: safe to run twice without printing twice.
        'idempotencyKey' => 'receipt-' . $order['number'] . '-' . date('Ymd-His'),
    ]);
} catch (CatzPrintException $e) {
    fwrite(STDERR, sprintf("Could not print (%d %s): %s\n", $e->status, $e->errorCode, $e->getMessage()));
    exit(1);
}

printf("Job %s is %s on %s (%s, billed as %s)\n", $job->id, $job->status, $job->printerName ?? '?', $job->live ? 'live' : 'test', $job->billedAs);
