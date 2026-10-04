<?php

namespace App;

enum Permission: string
{
    case Dashboard = 'dashboard';
    case Products = 'products';
    case Categories = 'categories';
    case Branches = 'branches';
    case Contacts = 'contacts';
    case Purchases = 'purchases';
    case Sales = 'sales';
    case SalesReturns = 'sales_returns';
    case Resales = 'resales';
    case PurchaseReturns = 'purchase_returns';
    case Inventory = 'inventory';
    case StockAdjustments = 'stock_adjustments';
    case StockChecks = 'stock_checks';
    case StockTransfers = 'stock_transfers';
    case WarrantySearch = 'warranty_search';
    case SalesTargets = 'sales_targets';
    case Expenses = 'expenses';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard overview',
            self::Products => 'Products',
            self::Categories => 'Product categories',
            self::Branches => 'Branches',
            self::Contacts => 'Customers and suppliers',
            self::Purchases => 'Purchases',
            self::Sales => 'Sales',
            self::SalesReturns => 'Sales returns',
            self::Resales => 'Resales',
            self::PurchaseReturns => 'Purchase returns',
            self::Inventory => 'Inventory and stock history',
            self::StockAdjustments => 'Manual stock adjustments',
            self::StockChecks => 'Stock checks',
            self::StockTransfers => 'Stock transfers',
            self::WarrantySearch => 'Warranty search',
            self::SalesTargets => 'Sales targets',
            self::Expenses => 'Expenses',
        };
    }
}
