<?php

namespace Molaprise\Molasync\Services\TDSynnex;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Molaprise\Molasync\Contracts\FreightQuoteServiceInterface;
use Throwable;

class FreightQuoteService implements FreightQuoteServiceInterface
{
    private const VERSION = '2.0';
    private string $endpoint;
    private string $account;
    private string $email;
    private string $password;
    private string $customer_name;

    public function __construct()
    {
        $env = config( 'molasync.tdsynnex.environment', 'test' );
        $base = rtrim( (string) config( "molasync.tdsynnex.{$env}.base_url" ), '/' );
        $this->endpoint = "{$base}/SynnexXML/FreightQuote";
        $this->account = (string) config( 'molasync.tdsynnex.account' );
        $this->email = (string) config( 'molasync.tdsynnex.email' );
        $this->password = (string) config( 'molasync.tdsynnex.password' );
        $this->customer_name = (string) config( 'molasync.tdsynnex.customer_name', 'Molagroup' );
    }

    // ── Interface implementation ─────────────────────────────────

    public function cheapest_method(
        array $ship_to,
        array $items,
        int   $warehouse = 3
    ): ?array
    {
        $methods = $this->all_methods( $ship_to, $items, $warehouse );

        if ( empty( $methods ) ) return null;

        return collect( $methods )
            ->sortBy( 'freight' )
            ->first();
    }

    public function all_methods(
        array $ship_to,
        array $items,
        int   $warehouse = 3
    ): ?array
    {
        $result = $this->quote_by_address( $ship_to, $items, $warehouse, '', null );

        if ( !$result ) return null;

        return $result[ 'methods' ] ?? null;
    }

    public function quote_by_address(
        array  $ship_to,
        array  $items,
        int    $warehouse = 3,
        string $ship_method = '',
        ?int   $service_level = null
    ): ?array
    {
        $xml = $this->build_xml( $ship_to, $items, $warehouse, $ship_method, $service_level );
        $response = $this->send( $xml );

        if ( !$response ) return null;

        return $this->parse( $response );
    }

    private function build_xml(
        array  $ship_to,
        array  $items,
        int    $warehouse,
        string $ship_method,
        ?int   $service_level
    ): string
    {
        $version = self::VERSION;
        $now = now()->format( 'Y-m-d\TH:i:s' );
        $account = $this->x( $this->account );
        $email = $this->x( $this->email );
        $password = $this->x( $this->password );
        $cust_name = $this->x( $this->customer_name );
        $ship_method = $this->x( $ship_method );
        $service_xml = $service_level !== null
            ? "<ServiceLevel>{$service_level}</ServiceLevel>"
            : '';

        // ShipTo block
        $name1 = $this->x( $ship_to[ 'name1' ] ?? '' );
        $name2 = $this->x( $ship_to[ 'name2' ] ?? '' );
        $address1 = $this->x( $ship_to[ 'address1' ] ?? '' );
        $city = $this->x( $ship_to[ 'city' ] ?? '' );
        $state = $this->x( $ship_to[ 'state' ] ?? '' );
        $zip = $this->x( $ship_to[ 'zip' ] ?? '' );
        $country = $this->x( $ship_to[ 'country' ] ?? 'US' );
        $address_type = $this->x( $ship_to[ 'address_type' ] ?? '0' );

        $name2_xml = $name2
            ? "<AddressName2>{$name2}</AddressName2>"
            : '<AddressName2/>';

        // Items block
        $items_xml = '';
        foreach ( $items as $index => $item ) {
            $line = $index + 1;
            $sku = $this->x( (string)( $item[ 'sku' ] ?? '' ) );
            $mfg = $this->x( (string)( $item[ 'mfg_part' ] ?? '' ) );
            $description = $this->x( (string)( $item[ 'description' ] ?? '' ) );
            $qty = (int)( $item[ 'quantity' ] ?? 1 );

            $items_xml .= <<<XML
        <Item lineNumber="{$line}">
            <SKU>{$sku}</SKU>
            <Description>{$description}</Description>
            <Quantity>{$qty}</Quantity>
            <MfgPartNumber>{$mfg}</MfgPartNumber>
        </Item>
XML;
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<SynnexB2B>
    <Credential>
        <UserID>{$email}</UserID>
        <Password>{$password}</Password>
    </Credential>
    <FreightQuoteRequest version="{$version}">
        <CustomerNumber>{$account}</CustomerNumber>
        <CustomerName>{$cust_name}</CustomerName>
        <RequestDateTime>{$now}</RequestDateTime>
        <ShipFromWarehouse>{$warehouse}</ShipFromWarehouse>
        <ShipTo>
            <AddressName1>{$name1}</AddressName1>
            {$name2_xml}
            <AddressLine1>{$address1}</AddressLine1>
            <City>{$city}</City>
            <State>{$state}</State>
            <ZipCode>{$zip}</ZipCode>
            <Country>{$country}</Country>
            <AddressType>{$address_type}</AddressType>
        </ShipTo>
        <ShipMethodCode>{$ship_method}</ShipMethodCode>
        {$service_xml}
        <Items>
            {$items_xml}
        </Items>
    </FreightQuoteRequest>
</SynnexB2B>
XML;
    }

    /**
     * Escape a value for safe XML output
     */
    private function x(string $value): string
    {
        return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
    }

    // ── XML builder ──────────────────────────────────────────────

    private function send(string $xml): ?string
    {
        try {
            $response = Http::withBody( $xml, 'text/xml; charset=UTF-8' )
                ->timeout( 15 )
                ->post( $this->endpoint );

            if ( !$response->successful() ) {
                Log::warning( 'TD Synnex Freight non-200', [
                    'url' => $this->endpoint,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ] );
                return null;
            }

            return $response->body();

        } catch ( Throwable $e ) {
            Log::error( 'TD Synnex Freight request failed', [
                'url' => $this->endpoint,
                'error' => $e->getMessage(),
            ] );
            return null;
        }
    }

    // ── HTTP transport ───────────────────────────────────────────

    private function parse(string $body): ?array
    {
        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $body );

        if ( !$xml ) {
            Log::warning( 'TD Synnex Freight parse failed', [ 'body' => $body ] );
            return null;
        }

        $response = $xml->FreightQuoteResponse ?? null;

        if ( !$response ) return null;

        // Handle error responses
        if ( isset( $response->ErrorMessage ) ) {
            Log::warning( 'TD Synnex Freight error response', [
                'error' => (string)$response->ErrorMessage,
                'detail' => (string)$response->ErrorDetail,
            ] );

            return [
                'success' => false,
                'error' => (string)$response->ErrorMessage,
                'error_detail' => (string)$response->ErrorDetail,
            ];
        }

        // Parse available ship methods
        $methods = [];
        foreach ( $response->AvailableShipMethods->AvailableShipMethod ?? [] as $method ) {
            $methods[] = [
                'code' => trim( (string)$method->attributes()[ 'code' ] ),
                'description' => trim( (string)$method->ShipMethodDescription ),
                'service_level' => (int)$method->ServiceLevel,
                'freight' => (float)$method->Freight,
            ];
        }

        // Sort cheapest first by default
        usort( $methods, fn($a, $b) => $a[ 'freight' ] <=> $b[ 'freight' ] );

        $total_sales = (float)$response->TotalSales;
        $free_threshold = (float)$response->FreeFreightThreshold;
        $is_free = $free_threshold > 0 && $total_sales >= $free_threshold;

        return [
            'success' => true,
            'total_weight' => (float)$response->TotalWeight,
            'total_sales' => $total_sales,
            'free_threshold' => $free_threshold,
            'is_free_freight' => $is_free,
            'ship_method_code' => (string)$response->ShipMethodCode,
            'ship_method_desc' => (string)$response->ShipMethodDescription,
            'address_type' => (string)( $response->ShipTo->AddressType ?? '' ),
            'ship_from' => [
                'number' => (string)$response->ShipFromWarehouse->Number,
                'zipcode' => (string)$response->ShipFromWarehouse->ZipCode,
                'city' => (string)$response->ShipFromWarehouse->City,
                'address' => (string)$response->ShipFromWarehouse->Addr,
            ],
            'methods' => $methods,
            'other_charges' => [
                'min_order_fee' => (float)( $response->OtherCharges->MinOrderFee ?? 0 ),
                'cod_fee' => (float)( $response->OtherCharges->CODFee ?? 0 ),
            ],
            'estimated_cost' => $is_free ? 0.00 : ( $methods[ 0 ][ 'freight' ] ?? 0.00 ),
            'estimated_method' => $is_free ? 'Free Shipping' : ( $methods[ 0 ][ 'description' ] ?? '' ),
        ];
    }

    // ── Response parser ──────────────────────────────────────────

    public function fastest_method(
        array $ship_to,
        array $items,
        int   $warehouse = 3
    ): ?array
    {
        $methods = $this->all_methods( $ship_to, $items, $warehouse );

        if ( empty( $methods ) ) return null;

        return collect( $methods )
            ->sortBy( 'service_level' )
            ->first();
    }

    // ── Helpers ──────────────────────────────────────────────────

    public function is_free_freight(
        array $ship_to,
        array $items,
        int   $warehouse = 3
    ): bool
    {
        $result = $this->quote_by_address( $ship_to, $items, $warehouse );

        if ( !$result ) return false;

        return $result[ 'is_free_freight' ] ?? false;
    }
}
