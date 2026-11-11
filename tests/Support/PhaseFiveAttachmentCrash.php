<?php

use GuzzleHttp\Handler\CurlMultiHandler;

define('F5_DOCUMENT_HELPERS_ONLY', true);
require __DIR__.'/PhaseFiveDocuments.php';

try {
    $transport = new CurlMultiHandler(['select_timeout' => 0.1]);
    $client = endpointF5('PHASE5_API');
    $token = loginF5();
    $original = "%PDF-1.4\n% Original de prueba antes del corte\n%%EOF";
    $replacement = "%PDF-1.4\n% Reemplazo de prueba\n%%EOF";
    if ($argv[1] === 'prepare') {
        $sale = completedSaleF5(saleF5());
        $contract = findDocumentF5('contratos', 'nota_venta_id', $sale)['id'];
        checkF5(attachF5($client, $contract, $original, 'original.pdf', uuidF5())->getStatusCode() === 200, 'Initial PDF unavailable.');
        $command = uuidF5();
        echo json_encode(['contract' => $contract, 'command' => $command,
            'marker' => checkpointF5($command, 'Adjunto', 'before')], JSON_THROW_ON_ERROR);
        exit;
    }
    $contract = (int) $argv[2];
    $command = $argv[3];
    if ($argv[1] === 'write') {
        $client = endpointF5('PHASE5_COMMERCIAL');
        try {
            attachF5($client, $contract, $replacement, '../reemplazo.pdf', $command);
        } catch (GuzzleHttp\Exception\TransferException) {
            exit;
        }
        throw new RuntimeException('Cut did not interrupt the response.');
    }
    if ($argv[1] !== 'verify') {
        throw new RuntimeException('Unexpected attachment exercise.');
    }
    checkF5(downloadF5("contratos/$contract/descargar", '.pdf') === $original, 'Old PDF was lost during the crash.');
    checkF5(attachF5($client, $contract, $replacement, '../reemplazo.pdf', $command)->getStatusCode() === 200, 'Lost attachment command was not retryable.');
    checkF5(downloadF5("contratos/$contract/descargar", '.pdf') === $replacement, 'Attachment retry did not change the PDF.');
    echo "F5-D04 attachment replay: $checks checks passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
