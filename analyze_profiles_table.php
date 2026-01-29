#!/usr/bin/env php
<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Router;
use App\Services\MikrotikService;

// Buffer output to avoid interleaving with progress messages
ob_start();

$routers = Router::where('is_active', true)->get();

if ($routers->isEmpty()) {
    echo "❌ No active routers found.\n";
    exit(1);
}

$service = app(MikrotikService::class);
$results = [];

fwrite(STDERR, "Analyzing " . $routers->count() . " routers...\n");

foreach ($routers as $router) {
    fwrite(STDERR, "📡 Checking {$router->name}...\n");
    
    try {
        $service->connect($router);
        $profiles = $service->getProfiles();
        $service->disconnect();
        
        $profileNames = array_map(fn($p) => $p['name'], $profiles);
        sort($profileNames);
        
        // Check for common isolation profile names
        $hasIsolirebilling = in_array('ISOLIREBILLING', $profileNames) || in_array('isolirebilling', $profileNames);
        $hasIsolated = in_array('ISOLATED', $profileNames) || in_array('isolated', $profileNames);
        $hasBlocked = in_array('blocked', $profileNames) || in_array('BLOCKED', $profileNames);
        
        // Find specifically what they use (heuristic: if simple match found)
        $isolationProfile = '-';
        if ($hasIsolirebilling) $isolationProfile = 'ISOLIREBILLING';
        elseif ($hasIsolated) $isolationProfile = 'ISOLATED';
        elseif ($hasBlocked) $isolationProfile = 'blocked';
        
        // Check for bandwidth profiles (just a count or list examples)
        $bandwidthProfiles = array_filter($profileNames, fn($n) => preg_match('/^\d+[MmKk]/', $n));
        
        $results[] = [
            'name' => $router->name,
            'ip' => $router->ip_address,
            'status' => 'Online',
            'isolation_profile' => $isolationProfile,
            'total_profiles' => count($profileNames),
            'examples' => implode(', ', array_slice($profileNames, 0, 5)) . (count($profileNames) > 5 ? ', ...' : ''),
            'all_profiles' => $profileNames 
        ];
        
    } catch (\Exception $e) {
        $results[] = [
            'name' => $router->name,
            'ip' => $router->ip_address,
            'status' => 'Offline/Error',
            'isolation_profile' => 'N/A',
            'total_profiles' => 0,
            'examples' => $e->getMessage(),
            'all_profiles' => []
        ];
        fwrite(STDERR, "   ❌ Error: {$e->getMessage()}\n");
    }
}

ob_end_clean();

// Generate Markdown Table
echo "| Router Name | Status | Isolation Profile Detected | Profile Count | Profile Examples |\n";
echo "|---|---|---|---|---|\n";

foreach ($results as $row) {
    $isoStatus = $row['isolation_profile'];
    if ($isoStatus === 'ISOLIREBILLING') {
        $isoStatus = "`ISOLIREBILLING` ✅";
    } elseif ($isoStatus !== '-' && $isoStatus !== 'N/A') {
        $isoStatus = "`{$isoStatus}` ⚠️";
    } elseif ($isoStatus === '-') {
        $isoStatus = "❌ *Missing*";
    }
    
    // Clean up examples for table
    $examples = $row['examples'];
    if (strlen($examples) > 50) {
        $examples = substr($examples, 0, 47) . '...';
    }
    
    echo "| **{$row['name']}** | {$row['status']} | {$isoStatus} | {$row['total_profiles']} | `{$examples}` |\n";
}

echo "\n\n### Detailed Profile List per Router\n\n";
foreach ($results as $row) {
    if ($row['status'] === 'Online') {
        echo "<details><summary><strong>{$row['name']}</strong> ({$row['total_profiles']} profiles)</summary>\n\n";
        echo "```text\n";
        echo implode(", ", $row['all_profiles']);
        echo "\n```\n</details>\n\n";
    }
}
