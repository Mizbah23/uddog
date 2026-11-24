<?php

namespace App\Http\Controllers;

use App\Models\SupportImpersonation;
use App\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StoreBackupController extends Controller
{
    private const STORE_TABLES = [
        'branches', 'categories', 'contacts', 'products', 'product_stocks',
        'documents', 'sale_installments', 'sale_payments', 'sale_corrections',
        'purchase_installments', 'purchase_payments', 'stock_movements',
        'stock_checks', 'stock_transfers', 'product_damages', 'expenses',
        'expense_categories', 'cash_movements', 'ecommerce_orders', 'sales_targets',
        'gold_jobs', 'gold_rates', 'supplier_advances', 'supplier_advance_deliveries',
        'supplier_tt_entries', 'staff_members', 'payroll_runs',
    ];

    private const CHILD_TABLES = [
        'document_items' => ['documents', 'document_id'],
        'stock_check_items' => ['stock_checks', 'stock_check_id'],
        'stock_transfer_items' => ['stock_transfers', 'stock_transfer_id'],
        'payroll_items' => ['payroll_runs', 'payroll_run_id'],
    ];

    public function __invoke(Request $request, TenantContext $tenant): BinaryFileResponse
    {
        $organizationId = $request->user()->organization_id;
        abort_unless($organizationId && ! $request->session()->has(SupportImpersonation::SESSION_KEY), 403);

        abort_if(config('tenancy.enabled') && $tenant->organization()?->id !== $organizationId, 403);

        $db = DB::connection($tenant->connectionName());
        $path = tempnam(sys_get_temp_dir(), 'uddog-store-');
        if ($path === false) {
            throw new RuntimeException('Could not create the store backup.');
        }

        try {
            $file = fopen($path, 'wb');
            if ($file === false) {
                throw new RuntimeException('Could not open the store backup.');
            }

            try {
                $write = static function (string $data) use ($file): void {
                    if (fwrite($file, $data) !== strlen($data)) {
                        throw new RuntimeException('Could not write the store backup.');
                    }
                };

                $db->transaction(function () use ($db, $organizationId, $write): void {
                    $write("-- Uddog store data backup for organization {$organizationId}\n");
                    $write("-- Import into an isolated database with the matching schema, store and user IDs, and no conflicting rows.\n");
                    $write("SET @uddog_foreign_key_checks = @@FOREIGN_KEY_CHECKS;\nSET FOREIGN_KEY_CHECKS = 0;\nSTART TRANSACTION;\n");

                    foreach ([...self::STORE_TABLES, ...array_keys(self::CHILD_TABLES)] as $table) {
                        $query = $db->table($table);
                        if (isset(self::CHILD_TABLES[$table])) {
                            [$parent, $foreignKey] = self::CHILD_TABLES[$table];
                            $query->whereIn($foreignKey, $db->table($parent)->select('id')->where('organization_id', $organizationId));
                        } else {
                            $query->where('organization_id', $organizationId);
                        }

                        foreach ($query->lazyById() as $row) {
                            // Keep restored orders valid without exporting their live tracking credentials.
                            if ($table === 'ecommerce_orders') {
                                $row->checkout_token = (string) Str::uuid();
                            }

                            $columns = array_map(
                                static fn (string $column): string => '`'.str_replace('`', '``', $column).'`',
                                array_keys((array) $row),
                            );
                            $values = array_map(
                                static fn (mixed $value): string => $value === null ? 'NULL' : "CONVERT(X'".bin2hex((string) $value)."' USING utf8mb4)",
                                (array) $row,
                            );
                            $write('INSERT INTO `'.$table.'` ('.implode(', ', $columns).') VALUES ('.implode(', ', $values).");\n");
                        }
                    }

                    $write("COMMIT;\nSET FOREIGN_KEY_CHECKS = @uddog_foreign_key_checks;\n");
                });
            } finally {
                fclose($file);
            }

            return response()->download($path, 'store-backup-'.now()->format('Y-m-d-His').'.sql', [
                'Content-Type' => 'application/sql',
                'Cache-Control' => 'private, no-store',
            ])->deleteFileAfterSend();
        } catch (\Throwable $exception) {
            unlink($path);
            throw $exception;
        }
    }
}
