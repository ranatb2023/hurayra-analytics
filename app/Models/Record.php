<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Record extends Model
{
    // id is the WooCommerce row id supplied by the CSV, not auto-incremented.
    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'import_id',
        'record_type',
        'status',
        'date_created_gmt',
        'ended_at',
        'total_amount',
        'subscription_id',
        'customer_id',
        'order_relationship',
        'billing_email',
        'attribution_type',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'device_type',
        'billing_period',
        'billing_interval',
        'next_payment_at',
        'coupon_code',
        'discount_amount',
        'net_amount',
        'tax_amount',
        'shipping_amount',
        'refunded_amount',
        'primary_product',
    ];

    protected $casts = [
        'date_created_gmt' => 'datetime',
        'ended_at' => 'datetime',
        'next_payment_at' => 'datetime',
        'discount_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'billing_interval' => 'integer',
        'total_amount' => 'decimal:2',
        'subscription_id' => 'integer',
        'customer_id' => 'integer',
        'import_id' => 'integer',
    ];

    // --- Canonical value sets (used for normalisation + validation) ---

    public const RECORD_TYPES = ['shop_order', 'shop_subscription'];

    /**
     * Order statuses this app understands.
     *
     * Anything outside this list is NOT business data: `trash` is a deleted
     * order, and WooCommerce plugins invent statuses freely. Counting
     * "everything that is not completed" swept 26 deleted orders worth GBP 3,099
     * into the not-completed metric, so unrecognised statuses are excluded and
     * surfaced instead of absorbed.
     */
    public const ORDER_STATUSES = ['completed', 'cancelled', 'failed', 'refunded', 'pending', 'processing', 'on-hold'];

    /** Statuses that are never business data, whatever else appears. */
    public const EXCLUDED_ORDER_STATUSES = ['trash', 'draft', 'checkout-draft', 'auto-draft'];

    public const SUBSCRIPTION_STATUSES = ['active', 'on-hold', 'cancelled', 'pending-cancel', 'expired', 'pending'];

    /**
     * Statuses that mean the subscription has left the lifecycle for good.
     * These are the only ones for which `ended_at` is meaningful — everything
     * else is still running, so it has no end date yet.
     */
    public const TERMINAL_SUBSCRIPTION_STATUSES = ['cancelled', 'expired'];

    /** Days in one WooCommerce billing period, for cycle-aware overdue maths. */
    public const PERIOD_DAYS = ['day' => 1, 'week' => 7, 'month' => 30, 'year' => 365];

    public const ORDER_RELATIONSHIPS = ['subscription', 'parent', 'renewal', 'one_time'];

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    public function scopeOrders($query)
    {
        return $query->where('record_type', 'shop_order');
    }

    public function scopeSubscriptions($query)
    {
        return $query->where('record_type', 'shop_subscription');
    }
}
