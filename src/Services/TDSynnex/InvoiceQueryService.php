<?php

namespace Molaprise\Molasync\Services\TDSynnex;

use Illuminate\Support\Facades\Http;
use Molaprise\Molasync\Contracts\InvoiceQueryServiceInterface;

class InvoiceQueryService implements InvoiceQueryServiceInterface
{
    public function queryByPurchaseOrder(string $poNumber): array
    {
        $response = Http::timeout(60)
            ->accept('application/xml')
            ->withHeaders(['Content-Type' => 'application/xml'])
            ->post($this->invoiceQueryUrl(), $this->buildQueryXml($poNumber));

        return [
            'status' => $response->successful() ? 'queried' : 'error',
            'po_number' => $poNumber,
            'http_status' => $response->status(),
            'raw_body' => $response->body(),
            'queried_at' => now()->toIso8601String(),
        ];
    }

    private function invoiceQueryUrl(): string
    {
        $environment = config('molasync.tdsynnex.environment', 'test');

        return (string) config("molasync.tdsynnex.{$environment}.invoice_query_url");
    }

    private function buildQueryXml(string $poNumber): string
    {
        $customerNumber = htmlspecialchars((string) config('molasync.tdsynnex.account'), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $poNumber = htmlspecialchars($poNumber, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<InvoiceQueryRequest>
  <CustomerNumber>{$customerNumber}</CustomerNumber>
  <PONumber>{$poNumber}</PONumber>
</InvoiceQueryRequest>
XML;
    }
}
