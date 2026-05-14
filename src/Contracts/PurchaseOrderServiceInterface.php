<?php

namespace Molaprise\Molasync\Contracts;

use Molaprise\Molasync\Data\Dto\POStatusRequest;
use Molaprise\Molasync\Data\Dto\POStatusResponse;
use Molaprise\Molasync\Data\Dto\POSubmitRequest;
use Molaprise\Molasync\Data\Dto\POSubmitResponse;
use Molaprise\Molasync\Exceptions\TDSynnex\{TDSynnexApiException, TDSynnexAuthException, TDSynnexNetworkException};

interface PurchaseOrderServiceInterface
{

    /**
     * Submit a Purchase Order to TD SYNNEX.
     *
     * Sends a real-time XML PO submission request and returns an immediate
     * acceptance/rejection response. Supports standard, software license,
     * end-user billing, drop-ship, Cisco, Apple, and v3.1 signature/quote orders.
     *
     * @param POSubmitRequest $request
     * @return POSubmitResponse
     *
     * @throws TDSynnexApiException
     * @throws TDSynnexAuthException
     * @throws TDSynnexNetworkException
     */
    public function submitPurchaseOrder(POSubmitRequest $request): POSubmitResponse;

    /**
     * Query the status of an existing Purchase Order.
     *
     * Retrieves the current status of a previously submitted PO, including
     * line-level details, shipment tracking, serial numbers, ETA, fees, and
     * any version-specific fields (IMEI, MAC, CustPOLineNo, etc.).
     *
     * @param POStatusRequest $request
     * @return POStatusResponse
     *
     * @throws TDSynnexApiException
     * @throws TDSynnexAuthException
     * @throws TDSynnexNetworkException
     */
    public function getPurchaseOrderStatus(POStatusRequest $request): POStatusResponse;

    /**
     * Submit a purchase order built from an internal sales order.
     *
     * Returns a normalized payload suitable for persistence on the internal
     * purchase order record.
     *
     * @return array<string, mixed>
     */
    public function submitPurchaseOrderForSalesOrder(object $purchaseOrder, object $salesOrder): array;
}
