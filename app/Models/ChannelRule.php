<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One raw-source-to-channel mapping. See App\Services\ChannelResolver.
 */
class ChannelRule extends Model
{
    protected $fillable = ['priority', 'channel', 'sources', 'mediums'];

    protected $casts = ['priority' => 'integer'];

    /** @return array<int, string> lower-cased, trimmed list values */
    public static function parseList(?string $list): array
    {
        return array_values(array_filter(array_map(
            fn (string $v) => strtolower(trim($v)),
            explode(',', (string) $list),
        ), fn (string $v) => $v !== ''));
    }
}
