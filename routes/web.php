<?php

use App\Http\Controllers\BranchController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\StockCheckController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\SalesTargetController;
use App\Http\Controllers\SupportImpersonationController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\WarrantyController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::get('session', [WorkspaceController::class, 'session']);
    Route::post('setup', [WorkspaceController::class, 'setup'])->middleware('throttle:3,1');
    Route::post('login', [WorkspaceController::class, 'login'])->middleware('throttle:5,1');
    Route::middleware(['auth', 'active'])->group(function () {
        Route::post('logout', [WorkspaceController::class, 'logout']);
        Route::delete('support-session', [SupportImpersonationController::class, 'destroy']);

        Route::middleware('subscribed')->group(function () {
            Route::get('branches', [BranchController::class, 'index']);
            Route::post('branches', [BranchController::class, 'store'])->middleware('permission:branches');
            Route::put('branches/{branch}', [BranchController::class, 'update'])->middleware('permission:branches');
            Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->middleware('permission:branches');
            Route::get('overview', [WorkspaceController::class, 'overview'])->middleware('permission:dashboard');
            Route::get('products', [WorkspaceController::class, 'products'])->middleware('permission:products,purchases,sales,resales,purchase_returns,inventory,stock_adjustments,stock_transfers');
            Route::post('products', [WorkspaceController::class, 'saveProduct'])->middleware('permission:products,purchases');
            Route::put('products/{product}', [WorkspaceController::class, 'saveProduct'])->middleware('permission:products');
            Route::get('categories', [CategoryController::class, 'index'])->middleware('permission:categories,products,purchases');
            Route::post('categories', [CategoryController::class, 'store'])->middleware('permission:categories');
            Route::put('categories/{category}', [CategoryController::class, 'update'])->middleware('permission:categories');
            Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->middleware('permission:categories');
            Route::get('contacts', [WorkspaceController::class, 'contacts'])->middleware('permission:contacts,purchases,sales,resales,purchase_returns');
            Route::post('contacts', [WorkspaceController::class, 'saveContact'])->middleware('permission:contacts,purchases,sales,resales,purchase_returns');
            Route::put('contacts/{contact}', [WorkspaceController::class, 'saveContact'])->middleware('permission:contacts');
            Route::get('documents', [WorkspaceController::class, 'documents'])->middleware('permission:purchases,sales,sales_returns,resales,purchase_returns');
            Route::post('documents', [WorkspaceController::class, 'saveDocument'])->middleware('permission:purchases,sales,sales_returns,resales,purchase_returns');
            Route::get('movements', [WorkspaceController::class, 'movements'])->middleware('permission:inventory,stock_adjustments');
            Route::post('products/{product}/adjust', [WorkspaceController::class, 'adjust'])->middleware('permission:stock_adjustments');
            Route::get('stock-checks', [StockCheckController::class, 'index'])->middleware('permission:stock_checks');
            Route::post('stock-checks', [StockCheckController::class, 'store'])->middleware('permission:stock_checks');
            Route::put('stock-checks/{stockCheck}', [StockCheckController::class, 'update'])->middleware('permission:stock_checks');
            Route::post('stock-checks/{stockCheck}/complete', [StockCheckController::class, 'complete'])->middleware('permission:stock_checks');
            Route::get('stock-transfers', [StockTransferController::class, 'index'])->middleware('permission:stock_transfers');
            Route::post('stock-transfers', [StockTransferController::class, 'store'])->middleware('permission:stock_transfers');
            Route::get('warranties', [WarrantyController::class, 'index'])->middleware('permission:warranty_search');
            Route::get('sales-targets', [SalesTargetController::class, 'index'])->middleware('permission:sales_targets');
            Route::post('sales-targets', [SalesTargetController::class, 'store'])->middleware('permission:sales_targets');
            Route::put('sales-targets/{salesTarget}', [SalesTargetController::class, 'update'])->middleware('permission:sales_targets');
            Route::delete('sales-targets/{salesTarget}', [SalesTargetController::class, 'destroy'])->middleware('permission:sales_targets');

            Route::middleware('role:superadmin,admin')->group(function () {
                Route::get('users', [UserManagementController::class, 'index']);
                Route::post('users', [UserManagementController::class, 'store']);
                Route::put('users/{managedUser}', [UserManagementController::class, 'update']);
            });
        });

        Route::middleware('role:superadmin')->group(function () {
            Route::get('clients', [ClientController::class, 'index']);
            Route::get('clients/{client}', [ClientController::class, 'show']);
            Route::post('clients', [ClientController::class, 'store']);
            Route::put('clients/{client}', [ClientController::class, 'update']);
            Route::post('clients/{client}/renew', [ClientController::class, 'renew']);
            Route::post('clients/{client}/impersonate', [SupportImpersonationController::class, 'store']);
        });
    });
});

Route::view('/{path?}', 'app')->where('path', '^(?!api).*$');
