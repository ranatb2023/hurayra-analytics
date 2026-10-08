<?php

namespace App\Services;

use App\Models\ChannelRule;
use Illuminate\Support\Facades\Schema;

/**
 * Maps raw attribution (utm_source, utm_medium, WooCommerce source type) to a
 * marketing channel using the `channel_rules` table.
 *
 * Rules are tried in priority order; the first whose source list contains the
 * raw source -- and whose medium list, if it has one, contains the medium --
 * wins. When an order carried no utm_source at all, WooCommerce's source type
 * (`typein`, `organic`, …) stands in, so a direct visit with no UTMs still
 * lands in Direct. Anything no rule matches is "Other" and is listed on the
 * Data Health page so a rule can be added for it.
 */
class ChannelResolver
{
    public const OTHER = 'Other';

    /** @var array<int, array{channel:string, sources:array<int,string>, mediums:array<int,string>}>|null */
    private ?array $rules = null;

    /** The channel for one row's attribution. */
    public function resolve(?string $source, ?string $medium = null, ?string $attributionType = null): string
    {
        return $this->match($source, $medium, $attributionType) ?? self::OTHER;
    }

    /** The channel, or null when no rule matches (an "unmapped" source). */
    public function match(?string $source, ?string $medium = null, ?string $attributionType = null): ?string
    {
        $raw = $this->clean($source) ?? $this->clean($attributionType);

        if ($raw === null) {
            return null;
        }

        $medium = $this->clean($medium);

        foreach ($this->rules() as $rule) {
            if (! in_array($raw, $rule['sources'], true)) {
                continue;
            }

            if ($rule['mediums'] !== [] && ($medium === null || ! in_array($medium, $rule['mediums'], true))) {
                continue;
            }

            return $rule['channel'];
        }

        return null;
    }

    /** Forget the cached rules (after an edit). */
    public function flush(): void
    {
        $this->rules = null;
    }

    /** @return array<int, array{channel:string, sources:array<int,string>, mediums:array<int,string>}> */
    private function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        if (! Schema::hasTable('channel_rules')) {
            return $this->rules = [];
        }

        return $this->rules = ChannelRule::query()
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->map(fn (ChannelRule $r) => [
                'channel' => $r->channel,
                'sources' => ChannelRule::parseList($r->sources),
                'mediums' => ChannelRule::parseList($r->mediums),
            ])
            ->all();
    }

    /** Lower-cased, trimmed; blank and the literal "NULL" are absent. */
    private function clean(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return $value === '' || $value === 'null' ? null : $value;
    }
}
