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
        'total_amount',
        'subscription_id',
        'customer_id',
        'order_relationship',
        'billing_email',
    ];

    protected $casts = [
        'date_created_gmt' => 'datetime',
        'total_amount' => 'decimal:2',
        'subscription_id' => 'integer',
        'customer_id' => 'integer',
        'import_id' => 'integer',
    ];

    // --- Canonical value sets (used for normalisation + validation) ---

    public const RECORD_TYPES = ['shop_order', 'shop_subscription'];

    public const ORDER_STATUSES = ['completed', 'cancelled', 'failed', 'refunded', 'pending', 'processing'];

    public const SUBSCRIPTION_STATUSES = ['active', 'on-hold', 'cancelled', 'pending-cancel', 'expired', 'pending'];

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
