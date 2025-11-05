<?php

namespace App\Services;

class IdService
{
    /**
     * Format a numeric or string id with a prefix and zero-padding.
     * Examples: format('PR', 138) => PR-000138; format('PO','2025-12') => PO-2025-12
     */
    public static function format(string $prefix, int|string $id, int $pad = 6): string
    {
        $prefix = strtoupper(trim($prefix));
        $raw = (string)$id;
        if ($raw === '') { return $prefix; }
        // If already contains non-digits (e.g., 2025-12), keep as-is after the dash
        if (!preg_match('/^\d+$/', $raw)) {
            return $prefix . '-' . $raw;
        }
        return $prefix . '-' . str_pad($raw, $pad, '0', STR_PAD_LEFT);
    }
}
