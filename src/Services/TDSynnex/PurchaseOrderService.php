<?php

namespace Molaprise\Molasync\Services\TDSynnex;

use Illuminate\Support\Facades\Http;
use Molaprise\Molasync\Contracts\PurchaseOrderServiceInterface;
use Molaprise\Molasync\Data\Dto\Address;
use Molaprise\Molasync\Data\Dto\BillTo;
use Molaprise\Molasync\Data\Dto\Contact;
use Molaprise\Molasync\Data\Dto\POLineItem;
use Molaprise\Molasync\Data\Dto\POStatusRequest;
use Molaprise\Molasync\Data\Dto\POStatusResponse;
use Molaprise\Molasync\Data\Dto\POSubmitRequest;
use Molaprise\Molasync\Data\Dto\POSubmitResponse;
use Molaprise\Molasync\Data\Dto\ShipMethod;
use Molaprise\Molasync\Data\Dto\Shipment;

class PurchaseOrderService implements PurchaseOrderServiceInterface
{
    public function submitPurchaseOrder(POSubmitRequest $request): POSubmitResponse
    {
        if (! config('molasync.tdsynnex.auto_submit_po', false)) {
            return new POSubmitResponse(
                customerNumber: $request->customerNumber,
                poNumber: $request->poNumber,
                code: 'DISABLED',
                responseDateTime: now()->toIso8601String(),
                responseElapsedTime: '0',
                items: [],
                reason: 'TD SYNNEX auto submission is disabled.',
            );
        }

        $response = Http::timeout(60)
            ->accept('application/xml')
            ->withHeaders(['Content-Type' => 'application/xml'])
            ->post($this->poUrl(), $this->buildSubmitXml($request));

        return new POSubmitResponse(
            customerNumber: $request->customerNumber,
            poNumber: $request->poNumber,
            code: $response->successful() ? 'SUBMITTED' : 'ERROR',
            responseDateTime: now()->toIso8601String(),
            responseElapsedTime: '0',
            items: [],
            reason: $response->successful() ? 'PO submitted to TD SYNNEX.' : 'PO submission failed.',
            errorMessage: $response->successful() ? null : $response->body(),
        );
    }

    public function getPurchaseOrderStatus(POStatusRequest $request): POStatusResponse
    {
        return new POStatusResponse(
            customerNumber: $request->customerNumber,
            poNumber: $request->poNumber,
            code: 'PENDING',
            responseDateTime: now()->toIso8601String(),
            responseElapsedTime: '0',
            items: [],
            reason: 'PO status query parsing not implemented yet.',
        );
    }

    public function submitPurchaseOrderForSalesOrder(object $purchaseOrder, object $salesOrder): array
    {
        $shipping = (array) ($salesOrder->shipping_address ?? []);
        $billing = (array) ($salesOrder->billing_address ?? []);
        $lineItems = array_values(array_map(
            fn (array $item, int $index) => new POLineItem(
                lineNumber: $index + 1,
                unitPrice: (float) ($item['unit_price'] ?? 0),
                orderQuantity: (int) ($item['quantity'] ?? 0),
                sku: $item['sku'] ?? null,
                manufacturerPartNumber: $item['manufacturer_part_number'] ?? ($item['part_number'] ?? null),
                productName: $item['name'] ?? null,
                shipFromWarehouse: isset($item['warehouse']) ? (int) $item['warehouse'] : null,
                custPOLineNo: (string) ($item['line_number'] ?? ($index + 1)),
            ),
            $salesOrder->line_items ?? [],
            array_keys($salesOrder->line_items ?? []),
        ));

        $request = new POSubmitRequest(
            customerNumber: (string) config('molasync.tdsynnex.account'),
            poNumber: $purchaseOrder->po_number,
            dropShipFlag: 'Y',
            shipment: new Shipment(
                address: new Address(
                    addressName1: (string) ($shipping['name'] ?? $salesOrder->external_order_number),
                    addressLine1: (string) ($shipping['address_1'] ?? ''),
                    city: (string) ($shipping['city'] ?? ''),
                    state: (string) ($shipping['state'] ?? ''),
                    zipCode: (string) ($shipping['postcode'] ?? ''),
                    country: (string) ($shipping['country'] ?? 'US'),
                    addressName2: $shipping['company'] ?? null,
                    addressLine2: $shipping['address_2'] ?? null,
                ),
                contact: new Contact(
                    contactName: (string) ($shipping['name'] ?? $salesOrder->external_order_number),
                    phoneNumber: $shipping['phone'] ?? null,
                    emailAddress: $shipping['email'] ?? null,
                ),
                shipMethod: new ShipMethod(),
            ),
            billTo: new BillTo(
                accountNumber: (string) config('molasync.tdsynnex.account'),
                addressName1: $billing['name'] ?? null,
                addressName2: $billing['company'] ?? null,
                addressLine1: $billing['address_1'] ?? null,
                addressLine2: $billing['address_2'] ?? null,
                city: $billing['city'] ?? null,
                state: $billing['state'] ?? null,
                zipCode: $billing['postcode'] ?? null,
                country: $billing['country'] ?? 'US',
            ),
            items: $lineItems,
            poDateTime: optional($salesOrder->approved_at)->toIso8601String(),
            xmlPOSubmitDateTime: now()->toIso8601String(),
        );

        $response = $this->submitPurchaseOrder($request);

        return [
            'status' => $response->code === 'SUBMITTED' ? 'submitted' : 'submission_pending',
            'external_po_number' => $response->poNumber,
            'tdsynnex_code' => $response->code,
            'reason' => $response->reason,
            'error_message' => $response->errorMessage,
            'response_at' => $response->responseDateTime,
        ];
    }

    private function buildSubmitXml(POSubmitRequest $request): string
    {
        $lines = '';

        foreach ($request->items as $item) {
            $sku = $this->xml($item->sku ?? '');
            $manufacturerPartNumber = $this->xml($item->manufacturerPartNumber ?? '');
            $productName = $this->xml($item->productName ?? '');
            $custPOLineNo = $this->xml($item->custPOLineNo ?? (string) $item->lineNumber);

            $lines .= <<<XML
<LineItem>
  <LineNumber>{$item->lineNumber}</LineNumber>
  <SKU>{$sku}</SKU>
  <ManufacturerPartNumber>{$manufacturerPartNumber}</ManufacturerPartNumber>
  <ProductName>{$productName}</ProductName>
  <UnitPrice>{$item->unitPrice}</UnitPrice>
  <OrderQuantity>{$item->orderQuantity}</OrderQuantity>
  <CustPOLineNo>{$custPOLineNo}</CustPOLineNo>
</LineItem>
XML;
        }

        $ship = $request->shipment->address;
        $bill = $request->billTo;

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<PurchaseOrderSubmit version="{$this->xml($request->apiVersion)}">
  <CustomerNumber>{$this->xml($request->customerNumber)}</CustomerNumber>
  <PONumber>{$this->xml($request->poNumber)}</PONumber>
  <DropShipFlag>{$this->xml($request->dropShipFlag)}</DropShipFlag>
  <ShipTo>
    <AddressName1>{$this->xml($ship->addressName1)}</AddressName1>
    <AddressLine1>{$this->xml($ship->addressLine1)}</AddressLine1>
    <AddressLine2>{$this->xml($ship->addressLine2 ?? '')}</AddressLine2>
    <City>{$this->xml($ship->city)}</City>
    <State>{$this->xml($ship->state)}</State>
    <ZipCode>{$this->xml($ship->zipCode)}</ZipCode>
    <Country>{$this->xml($ship->country)}</Country>
  </ShipTo>
  <BillTo>
    <AccountNumber>{$this->xml($bill->accountNumber)}</AccountNumber>
    <AddressName1>{$this->xml($bill->addressName1 ?? '')}</AddressName1>
    <AddressLine1>{$this->xml($bill->addressLine1 ?? '')}</AddressLine1>
    <AddressLine2>{$this->xml($bill->addressLine2 ?? '')}</AddressLine2>
    <City>{$this->xml($bill->city ?? '')}</City>
    <State>{$this->xml($bill->state ?? '')}</State>
    <ZipCode>{$this->xml($bill->zipCode ?? '')}</ZipCode>
    <Country>{$this->xml($bill->country ?? 'US')}</Country>
  </BillTo>
  <LineItems>{$lines}</LineItems>
</PurchaseOrderSubmit>
XML;
    }

    private function poUrl(): string
    {
        $environment = config('molasync.tdsynnex.environment', 'test');

        return (string) config("molasync.tdsynnex.{$environment}.po_url");
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
