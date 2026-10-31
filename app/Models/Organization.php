<?php

namespace App\Models;

use App\SubscriptionStatus;
use App\UserRole;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected $fillable = ['name', 'phone', 'address', 'subdomain', 'plan_name', 'annual_renew_fee', 'package_limits', 'subscription_status', 'subscription_ends_at', 'access_paused', 'gold_store', 'shared_stock_sales', 'ecommerce_enabled', 'notes', 'system_settings', 'tenant_database'];

    public const ANNUAL_RENEW_FEES = ['Starter' => 4000, 'Standard' => 7000, 'Premium' => 12000];

    public const PACKAGE_LIMITS = [
        'Starter' => ['branches' => 1, 'users' => 2, 'sale_invoices' => 1000],
        'Standard' => ['branches' => 2, 'users' => 5, 'sale_invoices' => 5000],
        'Premium' => ['branches' => 5, 'users' => 20, 'sale_invoices' => null],
    ];

    protected $appends = ['subscription_active', 'effective_package_limits'];

    protected function casts(): array
    {
        return [
            'subscription_status' => SubscriptionStatus::class,
            'annual_renew_fee' => 'decimal:2',
            'package_limits' => 'array',
            'subscription_ends_at' => 'date',
            'access_paused' => 'boolean',
            'gold_store' => 'boolean',
            'shared_stock_sales' => 'boolean',
            'ecommerce_enabled' => 'boolean',
            'system_settings' => 'array',
        ];
    }

    public function setup(): array
    {
        $saved = $this->system_settings ?? [];
        $store = array_replace([
            'allow_stock_adjustments' => true,
            'allow_stock_transfers' => true,
            'allow_cash_handover' => false,
            'allow_staff_custom_dates' => false,
            'default_reorder_level' => 0,
            'units' => ['pc', 'hali', '8 pcs', 'dozen', 'items', 'units', 'packs', 'boxes', 'cartons', 'sets', 'kg', 'g', 'L', 'oz', 'ounce', 'ভরি', 'রতি'],
        ], $saved['store'] ?? []);
        $store['unit_conversions'] = array_replace(self::defaultUnitConversions(), $saved['store']['unit_conversions'] ?? []);

        return [
            'sales' => array_replace([
                'allow_due_sales' => true,
                'allow_installment_sales' => true,
                'allow_sales_returns' => true,
                'allow_resales' => true,
                'default_payment_method' => 'cash',
            ], $saved['sales'] ?? []),
            'purchases' => ['allow_purchase_returns' => $saved['purchases']['allow_purchase_returns'] ?? true],
            'supplier_advances' => array_replace(['enabled' => false], $saved['supplier_advances'] ?? []),
            'store' => $store,
        ];
    }

    /** @return array<string, int> */
    public static function defaultUnitConversions(): array
    {
        return ['pc' => 1, 'hali' => 4, '8 pcs' => 8, 'dozen' => 12, 'items' => 1, 'units' => 1, 'packs' => 1, 'boxes' => 1, 'cartons' => 1, 'sets' => 1];
    }

    protected static function booted(): void
    {
        static::created(function (Organization $organization) {
            if (config('tenancy.enabled')) {
                return;
            }

            $organization->branches()->create([
                'name' => 'Main Branch',
                'code' => 'MAIN',
                'is_default' => true,
                'active' => true,
            ]);
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function admins(): HasMany
    {
        return $this->hasMany(User::class)->where('role', UserRole::Admin->value);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function supportImpersonations(): HasMany
    {
        return $this->hasMany(SupportImpersonation::class);
    }

    public function getAnnualRenewFeeAttribute(mixed $value): ?string
    {
        $fee = $value ?? self::ANNUAL_RENEW_FEES[$this->plan_name] ?? null;

        return $fee === null ? null : number_format((float) $fee, 2, '.', '');
    }

    public function packageLimits(): array
    {
        if ($this->isDemo()) {
            return ['branches' => null, 'users' => null, 'sale_invoices' => null];
        }

        return array_map(fn ($limit): ?int => $limit === null ? null : (int) $limit,
            array_replace(self::PACKAGE_LIMITS[$this->plan_name] ?? self::PACKAGE_LIMITS['Starter'], $this->package_limits ?? []));
    }

    public function getEffectivePackageLimitsAttribute(): ?array
    {
        return array_key_exists('plan_name', $this->getAttributes()) ? $this->packageLimits() : null;
    }

    public function subscriptionExpired(): bool
    {
        return $this->subscription_ends_at !== null
            && $this->subscription_ends_at->toDateString() < now('Asia/Dhaka')->toDateString();
    }

    public function hasActiveSubscription(): bool
    {
        return in_array($this->subscription_status, [SubscriptionStatus::Trial, SubscriptionStatus::Active], true)
            && ! $this->subscriptionExpired();
    }

    public function getSubscriptionActiveAttribute(): bool
    {
        return $this->hasActiveSubscription();
    }

    public function canUseWorkspace(): bool
    {
        return ! $this->access_paused && $this->hasActiveSubscription();
    }

    public function isDemo(): bool
    {
        return strtolower((string) $this->subdomain) === 'demo';
    }
}
