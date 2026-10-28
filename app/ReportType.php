<?php

namespace App;

enum ReportType: string
{
    case Sales = 'sales';
    case Purchases = 'purchases';
    case ProfitLoss = 'profit_loss';
    case ProductLedger = 'product_ledger';
    case ProductLifecycleLedger = 'product_lifecycle_ledger';
    case EmployeeSales = 'employee_sales';
    case Stock = 'stock';
    case Adjustments = 'adjustments';
    case BarcodeProducts = 'barcode_products';
    case BarcodeSales = 'barcode_sales';
    case Categories = 'categories';
    case Customers = 'customers';
    case CustomerLedger = 'customer_ledger';
    case CustomerDue = 'customer_due';
    case Suppliers = 'suppliers';
    case SupplierLedger = 'supplier_ledger';
    case SupplierDue = 'supplier_due';
    case SupplierTT = 'supplier_tt';
    case GeneralLedger = 'general_ledger';
    case CashMovement = 'cash_movement';

    /** @return list<Permission> */
    public function workspacePermissions(): array
    {
        return match ($this) {
            self::Sales => [Permission::Sales, Permission::Resales, Permission::SalesReturns],
            self::Purchases => [Permission::Purchases, Permission::PurchaseReturns],
            self::ProfitLoss => [Permission::Expenses],
            self::ProductLedger, self::EmployeeSales, self::BarcodeSales, self::CustomerDue => [Permission::Sales, Permission::Resales],
            self::ProductLifecycleLedger => [Permission::Purchases, Permission::Sales, Permission::Resales],
            self::SupplierDue => [Permission::Purchases, Permission::PurchaseReturns],
            self::SupplierTT => [Permission::SupplierAdvances],
            self::Stock => [Permission::Inventory, Permission::Products],
            self::Adjustments => [Permission::StockAdjustments],
            self::BarcodeProducts => [Permission::Products],
            self::Categories => [Permission::Categories, Permission::Products],
            self::Customers, self::CustomerLedger, self::Suppliers, self::SupplierLedger => [Permission::Contacts],
            self::GeneralLedger, self::CashMovement => [Permission::Sales, Permission::Resales, Permission::SalesReturns, Permission::Purchases, Permission::PurchaseReturns, Permission::Expenses],
        };
    }
}
