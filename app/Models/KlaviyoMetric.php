<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KlaviyoMetric extends Model
{
    protected $fillable = [
        'granularity', 'period_start', 'period_end',
        'delivery_rate', 'open_rate', 'click_rate', 'revenue', 'conversions', 'subscribers',
        'sub_created_conversions', 'sub_created_revenue', 'sub_renewal_conversions', 'sub_renewal_revenue',
        'flow_delivery_rate', 'flow_open_rate', 'flow_click_rate', 'flow_revenue', 'flow_conversions',
        'flow_sub_created_conversions', 'flow_sub_created_revenue', 'flow_sub_renewal_conversions', 'flow_sub_renewal_revenue',
        'status', 'error', 'synced_at',
    ];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'delivery_rate' => 'float',
        'open_rate' => 'float',
        'click_rate' => 'float',
        'revenue' => 'decimal:2',
        'conversions' => 'integer',
        'subscribers' => 'integer',
        'sub_created_conversions' => 'integer',
        'sub_created_revenue' => 'decimal:2',
        'sub_renewal_conversions' => 'integer',
        'sub_renewal_revenue' => 'decimal:2',
        'flow_delivery_rate' => 'float',
        'flow_open_rate' => 'float',
        'flow_click_rate' => 'float',
        'flow_revenue' => 'decimal:2',
        'flow_conversions' => 'integer',
        'flow_sub_created_conversions' => 'integer',
        'flow_sub_created_revenue' => 'decimal:2',
        'flow_sub_renewal_conversions' => 'integer',
        'flow_sub_renewal_revenue' => 'decimal:2',
        'synced_at' => 'datetime',
    ];
}
