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
        $routers = $query->paginate(15)->withQueryString();

        return Inertia::render('Routers/Index', [
            'routers' => $routers,
            'filters' => $request->only(['search', 'status', 'sort', 'direction']),
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
        $mikrotik = app(\App\Services\MikrotikService::class);

        try {
            $mikrotik->connect($router);
            $result = $mikrotik->testConnection();
            $mikrotik->disconnect();

            if ($result['success']) {
                return back()->with('success', "Successfully connected to {$router->name}!");
            } else {
                return back()->with('error', "Connection failed: {$result['error']}");
            }
        } catch (\Exception $e) {
            return back()->with('error', "Connection error: {$e->getMessage()}");
        }
    }

    /**
     * Scan this router for customers
     */
    public function scanRouter(Router $router)
    {
        try {
            \Log::info("Initiating scan for router: {$router->name} (ID: {$router->id})");
            
            \Artisan::call('network:scan', [
                '--router' => $router->id
            ]);
            
            $output = \Artisan::output();
            \Log::info("Scan output for {$router->name}: " . $output);
            
            return back()->with('success', "Scan initiated for {$router->name}. Check logs for details.");
        } catch (\Exception $e) {
            \Log::error("Scan failed for {$router->name}: {$e->getMessage()}");
            return back()->with('error', "Scan failed: {$e->getMessage()}");
        }
    }
}
