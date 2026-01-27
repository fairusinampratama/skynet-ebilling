<?php

namespace App\Http\Controllers\Api;

use App\Models\Router;
use App\Services\MikrotikService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class RouterStatsController extends Controller
{
    protected MikrotikService $mikrotikService;

    public function __construct(MikrotikService $mikrotikService)
    {
        $this->mikrotikService = $mikrotikService;
    }

    /**
     * Get live statistics from MikroTik router
     */
    public function getLiveStats(Router $router)
    {
        $cacheKey = "router_stats_{$router->id}";
        $cacheDuration = 60; // 1 minute

        try {
            // Check cache first
            $cached = \Cache::get($cacheKey);
            if ($cached) {
                return response()->json($cached);
            }

            // Fetch fresh data
            $this->mikrotikService->connect($router);

            // Get active connections
            $activeConnections = $this->mikrotikService->getActiveConnections();
            
            // Get system resources
            $systemResources = $this->mikrotikService->testConnection();

            $this->mikrotikService->disconnect();

            $responseData = [
                'success' => true,
                'data' => [
                    'active_connections' => array_map(function($conn) {
                        return [
                            'name' => $conn['name'] ?? 'Unknown',
                            'address' => $conn['address'] ?? 'N/A',
                            'uptime' => $conn['uptime'] ?? '0',
                            'encoding' => $conn['encoding'] ?? 'N/A',
                            'caller_id' => $conn['caller-id'] ?? 'N/A',
                        ];
                    }, $activeConnections),
                    'total_online' => count($activeConnections),
                    'system_info' => $systemResources['data'] ?? [],
                ],
                'last_updated' => now()->toIso8601String(),
                'cached' => false,
            ];

            // Cache the result
            \Cache::put($cacheKey, $responseData, $cacheDuration);

            return response()->json($responseData);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'last_updated' => now()->toIso8601String(),
            ], 500);
        }
    }
}
