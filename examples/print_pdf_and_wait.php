<?php

declare(strict_types=1);

/*
 * Seal a PDF to CatzPrint's public key, print it, and wait until it's done.
 *
 *   CATZPRINT_API_KEY=cz_test_… php examples/print_pdf_and_wait.php [file.pdf]
 *
 * Optional: CATZPRINT_PRINTER_ID (otherwise the best "office" printer),
 * CATZPRINT_WAIT_SECONDS (default 60).
 */

require __DIR__ . '/autoload.php';

use CatzPrint\Client;
use CatzPrint\Exception\CatzPrintException;
use CatzPrint\Exception\TimeoutException;

$file = $argv[1] ?? __DIR__ . '/hello.pdf';
$catz = new Client();

$target = getenv('CATZPRINT_PRINTER_ID') ? ['printerId' => getenv('CATZPRINT_PRINTER_ID')] : ['role' => 'office'];

try {
    // A path or the PDF bytes both work. sealed: true encrypts the file
    // before it leaves this machine (needs ext-sodium).
    $job = $catz->printPdf($file, $target + ['sealed' => true, 'copies' => 1]);
    printf("Sent %s as job %s (%s)\n", basename($file), $job->id, $job->status);

    $job = $catz->jobs->waitFor($job->id, timeout: (float) (getenv('CATZPRINT_WAIT_SECONDS') ?: 60), interval: 1.0);
    if ($job->isPrinted()) {
        printf("Printed on %s at %s\n", $job->printerName ?? '?', $job->printedAt?->format(DATE_RFC2822) ?? '?');
    } else {
        printf("Finished as %s: %s\n", $job->status, $job->statusDetail);
        exit(1);
    }
} catch (TimeoutException $e) {
    fwrite(STDERR, $e->getMessage() . " Is the station online?\n");
    exit(2);
} catch (CatzPrintException $e) {
    fwrite(STDERR, sprintf("Could not print (%d %s): %s\n", $e->status, $e->errorCode, $e->getMessage()));
    exit(1);
}
