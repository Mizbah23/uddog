<?php

namespace App;

enum ReportType: string
{
    case Sales = 'sales';
    case Purchases = 'purchases';
    case ProfitLoss = 'profit_loss';
    case EmployeeSales = 'employee_sales';
    case Stock = 'stock';
    case Adjustments = 'adjustments';
    case BarcodeProducts = 'barcode_products';
    case BarcodeSales = 'barcode_sales';
    case Categories = 'categories';
    case Customers = 'customers';
    case CustomerLedger = 'customer_ledger';
    case CustomerDue = 'customer_due';

    /** @return list<Permission> */
    public function workspacePermissions(): array
    {
        return match ($this) {
            self::Sales => [Permission::Sales, Permission::Resales, Permission::SalesReturns],
            self::Purchases => [Permission::Purchases, Permission::PurchaseReturns],
            self::ProfitLoss, self::EmployeeSales, self::BarcodeSales, self::CustomerDue => [Permission::Sales, Permission::Resales],
            self::Stock => [Permission::Inventory, Permission::Products],
            self::Adjustments => [Permission::StockAdjustments],
            self::BarcodeProducts => [Permission::Products],
            self::Categories => [Permission::Categories, Permission::Products],
            self::Customers, self::CustomerLedger => [Permission::Contacts],
        };
    }
}
