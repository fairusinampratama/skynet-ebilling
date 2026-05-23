<?php

namespace App\Http\Controllers;

use App\Http\Requests\RouterStoreRequest;
use App\Http\Requests\RouterUpdateRequest;
use App\Jobs\SyncRouterJob;
use App\Models\Router;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RouterController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Router::query()->withCount('customers');

        // Search
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            $isActive = $request->input('status') === 'active';
            $query->where('is_active', $isActive);
        }

        // Sorting
        $sortField = $request->input('sort', 'name');
        $sortDirection = $request->input('direction', 'asc');
        $query->orderBy($sortField, $sortDirection);

        // Paginate
        $limit = $request->input('limit', 25);
        $routers = $query->paginate($limit)->withQueryString();

        return Inertia::render('Routers/Index', [
            'routers' => $routers,
            'filters' => $request->only(['search', 'status', 'sort', 'direction', 'limit']),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Routers/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(RouterStoreRequest $request)
    {
        $validated = $request->validated();

        Router::create($validated);

        return redirect()->route('routers.index')
            ->with('success', 'Router added successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Router $router)
    {
        $router->load(['profiles']);
        $router->loadCount([
            'customers',
            'stagedCustomers as staged_unmatched_customers_count' => fn ($query) => $query->where('status', 'unmatched'),
        ]);
        // Customers will be loaded lazily via API

        return Inertia::render('Routers/Show', [
            'router' => $router,
        ]);
    }

    /**
     * Get paginated customers for this router (API)
     */
    public function customers(Request $request, Router $router)
    {
        $query = $router->customers()->ebilling()->with('package');

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('pppoe_user', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        $customers = $query->latest()->paginate(20);

        return response()->json($customers);
    }

    /**
     * Get list of PPP profiles from this router (API)
     */
    public function getProfiles(Router $router)
    {
        // Read from database (synced via routers:sync-profiles command)
        $profiles = \App\Models\RouterProfile::where('router_id', $router->id)
            ->get()
            ->map(function ($profile) {
                return [
                    'name' => $profile->name,
                    'rate_limit' => $profile->rate_limit,
                    'bandwidth' => $profile->bandwidth,
                    'local_address' => $profile->local_address,
                    'remote_address' => $profile->remote_address,
                ];
            });

        return response()->json($profiles);
    }

    /**
     * Get live router stats for the router detail page.
     */
    public function liveStats(Router $router)
    {
        $cacheKey = "router:{$router->id}:live-stats";

        try {
            $payload = \Cache::remember($cacheKey, now()->addSeconds(60), function () use ($router) {
                $mikrotik = new \App\Services\MikrotikService;
                $mikrotik->connect($router, ['timeout' => 5, 'attempts' => 1]);

                try {
                    $resourceQuery = new \RouterOS\Query('/system/resource/print');
                    $resource = $mikrotik->getClient()->query($resourceQuery)->read();
                    $activeConnections = $mikrotik->getActiveConnections();

                    return [
                        'data' => [
                            'active_connections' => $activeConnections,
                            'total_online' => count($activeConnections),
                            'system_info' => $resource[0] ?? [],
                        ],
                        'last_updated' => now()->toISOString(),
                    ];
                } finally {
                    $mikrotik->disconnect();
                }
            });

            return response()->json($payload + ['cached' => true]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 503);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Router $router)
    {
        return Inertia::render('Routers/Edit', [
            'router' => $router,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(RouterUpdateRequest $request, Router $router)
    {
        $validated = $request->validated();

        // Only update password if provided
        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $router->update($validated);

        return redirect()->route('routers.index')
            ->with('success', 'Router updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Router $router)
    {
        // Prevent deletion if router has customers
        if ($router->customers()->ebilling()->count() > 0) {
            return back()->with('error', 'Cannot delete router with assigned customers.');
        }

        $router->delete();

        return redirect()->route('routers.index')
            ->with('success', 'Router deleted successfully.');
    }

    /**
     * Test connection to router
     */
    public function testConnection(Router $router)
    {
        $syncService = app(\App\Services\RouterSyncService::class);
        $result = $syncService->syncHealthStatus($router);

        if ($result['success']) {
            return back()->with('success', $result['message']);
        } else {
            return back()->with('error', $result['message']);
        }
    }

    public function syncOnlineStatus(Router $router)
    {
        return $this->testConnection($router);
    }

    /**
     * Scan this router for customers
     */
    public function scanRouter(Router $router)
    {
        if (! $router->is_active) {
            return back()->with('error', "Cannot scan inactive router. Please enable it first or 'Test Connection'.");
        }

        try {
            \Log::info("Initiating synchronous scan for router: {$router->name} (ID: {$router->id})");

            $syncService = app(\App\Services\RouterSyncService::class);
            $stats = $syncService->syncCustomers($router);

            $message = "Scan completed. Mapped: {$stats['mapped']}, Router-only staged: {$stats['staged_router_only']}, eBilling missing: {$stats['not_found_ebilling']}";

            return back()->with('success', $message);
        } catch (\Exception $e) {
            \Log::error("Scan failed for {$router->name}: {$e->getMessage()}");

            return back()->with('error', "Failed to scan: {$e->getMessage()}");
        }
    }

    /**
     * Unified Sync (Test + Scan)
     */
    public function sync(Router $router)
    {
        $router->refresh();
        $isLocked = in_array($router->sync_status, ['queued', 'running'], true)
            && $router->sync_lock_until
            && $router->sync_lock_until->isFuture();

        if ($isLocked) {
            return back()->with('success', "{$router->name}: Sync is already {$router->sync_status}.");
        }

        $router->update([
            'sync_status' => 'queued',
            'sync_started_at' => null,
            'sync_finished_at' => null,
            'sync_lock_until' => now()->addMinutes(10),
            'sync_message' => 'Full sync is queued.',
        ]);

        SyncRouterJob::dispatch($router->id);

        return back()->with('success', "{$router->name}: Full sync queued.");
    }

    /**
     * Sync All Active Routers
     */
    public function syncAll()
    {
        $routers = Router::where('is_active', true)->get();
        $results = [
            'total' => $routers->count(),
            'synced' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($routers as $router) {
            $isLocked = in_array($router->sync_status, ['queued', 'running'], true)
                && $router->sync_lock_until
                && $router->sync_lock_until->isFuture();

            if ($isLocked) {
                $results['failed']++;
                $results['errors'][] = "{$router->name}: already {$router->sync_status}";

                continue;
            }

            $router->update([
                'sync_status' => 'queued',
                'sync_started_at' => null,
                'sync_finished_at' => null,
                'sync_lock_until' => now()->addMinutes(10),
                'sync_message' => 'Full sync is queued.',
            ]);

            SyncRouterJob::dispatch($router->id);
            $results['synced']++;
        }

        $message = "Queued {$results['synced']}/{$results['total']} routers";
        if ($results['failed'] > 0) {
            $message .= ". {$results['failed']} failed.";
        }

        return back()->with('success', $message);
    }
}
