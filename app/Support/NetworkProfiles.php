<?php

namespace App\Support;

class NetworkProfiles
{
    public static function isIsolationLike(?string $profile): bool
    {
        $normalized = strtolower(trim((string) $profile));

        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'isolir')
            || str_contains($normalized, 'isolirebilling')
            || str_contains($normalized, 'isolated');
    }

    public static function effectiveCustomerProfile(object $customer): ?string
    {
        if (($customer->status ?? null) === 'isolated' && filled($customer->previous_profile ?? null)) {
            return $customer->previous_profile;
        }

        return $customer->mikrotik_profile ?? null;
    }
}
