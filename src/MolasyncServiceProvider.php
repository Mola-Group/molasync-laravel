<?php

namespace Molaprise\Molasync;

use Illuminate\Support\ServiceProvider;
use Molaprise\Molasync\Contracts\EtilizeCatalogServiceInterface;
use Molaprise\Molasync\Contracts\FreightQuoteServiceInterface;
use Molaprise\Molasync\Contracts\InvoiceQueryServiceInterface;
use Molaprise\Molasync\Contracts\PriceAvailabilityServiceInterface;
use Molaprise\Molasync\Contracts\PurchaseOrderServiceInterface;
use Molaprise\Molasync\Contracts\TaxServiceInterface;
use Molaprise\Molasync\Services\Etilize\EtilizeCatalogService;
use Molaprise\Molasync\Services\TDSynnex\FreightQuoteService;
use Molaprise\Molasync\Services\TDSynnex\InvoiceQueryService;
use Molaprise\Molasync\Services\TDSynnex\PriceAvailabilityService;
use Molaprise\Molasync\Services\TDSynnex\PurchaseOrderService;
use Molaprise\Molasync\Services\Ziptax\TaxService;

class MolasyncServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes( [
            __DIR__ . '/../config/molasync.php' => config_path( 'molasync.php' ),
        ] );

        $this->app->bind( FreightQuoteServiceInterface::class, FreightQuoteService::class );
        $this->app->bind( InvoiceQueryServiceInterface::class, InvoiceQueryService::class );
        $this->app->bind( PriceAvailabilityServiceInterface::class, PriceAvailabilityService::class );
        $this->app->bind( PurchaseOrderServiceInterface::class, PurchaseOrderService::class );
        $this->app->bind( TaxServiceInterface::class, TaxService::class );
    }

    public function register(): void
    {
        $this->mergeConfigFrom( __DIR__ . '/../config/molasync.php', 'molasync' );

        $this->app->bind( EtilizeCatalogServiceInterface::class, EtilizeCatalogService::class );
    }
}
