# Molasync Laravel

Laravel package for Molaprise commerce integrations, including TD SYNNEX, Etilize, and ZipTax service layers.

## What It Provides

- TD SYNNEX service contracts and implementations for price/availability, freight quote, invoice lookup, and purchase order flows
- Etilize catalog resolution and related Eloquent models
- ZipTax service integration
- Laravel service provider and publishable `molasync.php` config

## Requirements

- PHP 8.2+
- Laravel 12

## Install From GitHub

Add the repository to your application's `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/Mola-Group/molasync-laravel.git"
    }
  ]
}
```

Require the package:

```bash
composer require molaprise/molasync-laravel:dev-main
```

Publish config if needed:

```bash
php artisan vendor:publish --provider="Molaprise\\Molasync\\MolasyncServiceProvider"
```

## Local Development

For local package development, switch the consuming app to a Composer `path` repository pointing at your checkout.

## Notes

`submitPurchaseOrderForSalesOrder()` intentionally accepts generic host objects so the package does not depend on an application-specific `App\\Models` namespace. The host application is responsible for passing objects with the expected purchase-order and sales-order fields.
