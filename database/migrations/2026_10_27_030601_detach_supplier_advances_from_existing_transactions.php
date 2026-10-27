<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasLegacyAdvances = Schema::hasTable('supplier_advances') && Schema::hasColumn('supplier_advances', 'contact_id');
        $hasLegacyDeliveries = Schema::hasTable('supplier_advance_deliveries') && Schema::hasColumn('supplier_advance_deliveries', 'document_id');
        if (! $hasLegacyAdvances && ! $hasLegacyDeliveries) {
            return;
        }

        if (DB::table('supplier_advances')->doesntExist() && DB::table('supplier_advance_deliveries')->doesntExist()) {
            Schema::dropIfExists('supplier_advance_deliveries');
            Schema::dropIfExists('supplier_advances');
            (require __DIR__.'/2026_10_26_172539_create_supplier_advances_and_deliveries.php')->up();

            return;
        }

        if ($hasLegacyAdvances) {
            if (! Schema::hasColumn('supplier_advances', 'company_name')) {
                Schema::table('supplier_advances', function (Blueprint $table): void {
                    $table->string('company_name')->default('Company not recorded');
                });
            }
            DB::table('supplier_advances')->select(['id', 'contact_id'])->orderBy('id')->chunkById(100, function ($advances): void {
                foreach ($advances as $advance) {
                    $name = DB::table('contacts')->where('id', $advance->contact_id)->value('name');
                    DB::table('supplier_advances')->where('id', $advance->id)->update(['company_name' => $name ?: 'Company not recorded']);
                }
            });

            $this->dropLegacyForeignKeys('supplier_advances', ['organization_id', 'branch_id', 'contact_id', 'created_by']);
            Schema::table('supplier_advances', function (Blueprint $table): void {
                $table->dropColumn('contact_id');
            });
        }

        if ($hasLegacyDeliveries) {
            if (! Schema::hasColumn('supplier_advance_deliveries', 'product_details')) {
                Schema::table('supplier_advance_deliveries', function (Blueprint $table): void {
                    $table->string('product_details')->default('Recorded product delivery');
                });
            }
            DB::table('supplier_advance_deliveries')->select(['id', 'document_id'])->orderBy('id')->chunkById(100, function ($deliveries): void {
                foreach ($deliveries as $delivery) {
                    $number = DB::table('documents')->where('id', $delivery->document_id)->value('number');
                    DB::table('supplier_advance_deliveries')->where('id', $delivery->id)->update([
                        'product_details' => $number ? 'Recorded delivery '.$number : 'Recorded product delivery',
                    ]);
                }
            });

            $this->dropLegacyForeignKeys('supplier_advance_deliveries', ['organization_id', 'branch_id', 'document_id', 'purchase_payment_id', 'created_by']);
            Schema::table('supplier_advance_deliveries', function (Blueprint $table): void {
                $table->dropColumn(['document_id', 'purchase_payment_id']);
            });
        }
    }

    private function dropLegacyForeignKeys(string $tableName, array $columns): void
    {
        $foreignKeys = array_filter(Schema::getForeignKeys($tableName), fn (array $foreignKey): bool => count(array_intersect($foreignKey['columns'], $columns)) > 0);
        Schema::table($tableName, function (Blueprint $table) use ($foreignKeys): void {
            foreach ($foreignKeys as $foreignKey) {
                $table->dropForeign($foreignKey['name']);
            }
        });
    }

    public function down(): void
    {
        // Existing contact and purchase links cannot be reconstructed for new standalone records.
    }
};
