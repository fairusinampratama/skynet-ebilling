<?php

namespace App\Support;

use App\Models\Package;
use App\Models\RouterProfile;
use Illuminate\Support\Str;

class FiveMUpgrade
{
    public static function isFiveM(?string $profile): bool
    {
        $profile = Str::lower(trim((string) $profile));

        return in_array($profile, ['5', '5m', '5mb', '5mbps'], true)
            || str_starts_with($profile, '5m');
    }

    public static function isTargetProfile(?string $profile): bool
    {
        return in_array(Str::lower(trim((string) $profile)), ['10mb', '10m'], true);
    }

    public static function targetProfileForRouter(int $routerId): ?RouterProfile
    {
        return RouterProfile::query()
            ->where('router_id', $routerId)
            ->where('name', '10MB')
            ->first()
            ?: RouterProfile::query()
                ->where('router_id', $routerId)
                ->where('name', '10M')
                ->first();
    }

    public static function targetPackageName(Package $package, string $targetProfile): string
    {
        $routerName = $package->router?->name;
        $name = $package->name;

        if ($routerName) {
            $quotedRouter = preg_quote($routerName, '/');
            $quotedProfile = preg_quote((string) $package->mikrotik_profile, '/');

            $renamed = preg_replace(
                "/ - {$quotedRouter} - {$quotedProfile}$/i",
                " - {$routerName} - {$targetProfile}",
                $name
            );

            if (is_string($renamed) && $renamed !== $name) {
                return $renamed;
            }
        }

        return $name;
    }

    public static function uniquePackageCode(string $name): string
    {
        $base = Str::upper(Str::slug($name));
        $base = $base !== '' ? $base : 'PACKAGE';
        $candidate = $base;
        $suffix = 1;

        while (Package::where('code', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }
}
