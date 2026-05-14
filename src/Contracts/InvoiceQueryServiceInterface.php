<?php

namespace Molaprise\Molasync\Contracts;

interface InvoiceQueryServiceInterface
{
    /**
     * Query TD SYNNEX invoice data by PO number.
     *
     * @return array<string, mixed>
     */
    public function queryByPurchaseOrder(string $poNumber): array;
}
