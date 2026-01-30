<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Router;
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
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'ip_address' => 'required|ip',
            'port' => 'required|integer|min:1|max:65535',
            'winbox_port' => 'nullable|integer|min:1|max:65535',
            'username' => 'required|string|max:255',
            'password' => 'required|string',
            'is_active' => 'boolean',
        ]);

        Router::create($validated);

        return redirect()->route('routers.index')
            ->with('success', 'Router added successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Router $router)
    {
        $router->loadCount('customers');
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
        $query = $router->customers()->with('package');

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
    public function update(Request $request, Router $router)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'ip_address' => 'required|ip',
            'port' => 'required|integer|min:1|max:65535',
            'winbox_port' => 'nullable|integer|min:1|max:65535',
            'username' => 'required|string|max:255',
            'password' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

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
        if ($router->customers()->count() > 0) {
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

    /**
     * Scan this router for customers
     */
    public function scanRouter(Router $router)
    {
        if (!$router->is_active) {
            return back()->with('error', "Cannot scan inactive router. Please enable it first or 'Test Connection'.");
        }

        try {
            \Log::info("Initiating synchronous scan for router: {$router->name} (ID: {$router->id})");
            
            $syncService = app(\App\Services\RouterSyncService::class);
            $stats = $syncService->syncCustomers($router);
            
            $message = "Scan completed. Mapped: {$stats['mapped']}, Orphans: {$stats['orphaned']}";
            if ($stats['synced_package'] > 0) $message .= ", Packages Updated: {$stats['synced_package']}";
            
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
        $syncService = app(\App\Services\RouterSyncService::class);
        $result = $syncService->fullSync($router);

        if ($result['success']) {
            $scan = $result['scan'];
            // Simplify message for toast
            $msg = "{$router->name}: Synced! Online: {$router->current_online_count}. Scan: {$scan['mapped']} mapped, {$scan['orphaned']} orphans.";
            return back()->with('success', $msg);
        } else {
            return back()->with('error', "{$router->name}: Sync Failed - " . $result['error']);
        }
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
            'errors' => []
        ];

        $syncService = app(\App\Services\RouterSyncService::class);

        foreach ($routers as $router) {
            try {
                $syncService->fullSync($router);
                $results['synced']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = "{$router->name}: {$e->getMessage()}";
            }
        }

        $message = "Synced {$results['synced']}/{$results['total']} routers";
        if ($results['failed'] > 0) {
            $message .= ". {$results['failed']} failed.";
        }

        return back()->with('success', $message);
    }
}
