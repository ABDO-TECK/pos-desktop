<?php
/**
 * Service Container Bindings
 * ربط كل Interface بالتطبيق (Implementation) الخاص به.
 *
 * يتم تحميل هذا الملف في index.php بعد إنشاء الـ Container.
 */

use App\Contracts\ProductServiceInterface;
use App\Contracts\SaleServiceInterface;
use App\Contracts\InventoryServiceInterface;
use App\Contracts\CustomerServiceInterface;
use App\Contracts\SupplierServiceInterface;
use App\Contracts\BackupServiceInterface;
use App\Contracts\LoyaltyServiceInterface;

use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\InventoryService;
use App\Services\CustomerService;
use App\Services\SupplierService;
use App\Services\BackupService;
use App\Services\LoyaltyService;

/** @var \App\Core\Container $container */

$container->bind(ProductServiceInterface::class,   ProductService::class);
$container->bind(SaleServiceInterface::class,       SaleService::class);
$container->bind(InventoryServiceInterface::class,  InventoryService::class);
$container->bind(CustomerServiceInterface::class,   CustomerService::class);
$container->bind(SupplierServiceInterface::class,   SupplierService::class);
$container->bind(BackupServiceInterface::class,     BackupService::class);
$container->bind(LoyaltyServiceInterface::class,    LoyaltyService::class);
