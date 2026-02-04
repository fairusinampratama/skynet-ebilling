<?php

namespace App\Http\Controllers;

use App\Models\Package;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PackageController extends Controller
{
    /**
     * Display a listing of packages
     */
    public function index()
    {
        $packages = Package::with(['router:id,name'])
                          ->withCount('customers')
                          ->orderBy('price', 'asc')
                          ->get();

        return Inertia::render('Packages/Index', [
            'packages' => $packages,
        ]);
    }

    /**
     * Show the form for creating a new package
     */
    public function create()
    {
        $routers = \App\Models\Router::with('profiles')
            ->where('is_active', true)
            ->get()
            ->map(function ($router) {
                return [
                    'id' => $router->id,
                    'name' => $router->name,
                    'profiles' => $router->profiles->map(fn($p) => [
                        'name' => $p->name,
                        'bandwidth' => $p->bandwidth,
                        'rate_limit' => $p->rate_limit,
                    ])
                ];
            });

        return Inertia::render('Packages/Create', [
            'routers' => $routers
        ]);
    }

    /**
     * Store a newly created package
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'router_id' => 'required|exists:routers,id', // STRICT
            'price' => 'required|numeric|min:0',
            'bandwidth_label' => 'required|string|max:255',
            'mikrotik_profile' => 'nullable|string|max:255',
        ]);

        Package::create($validated);

        return redirect()->route('packages.index')
            ->with('success', 'Package created successfully.');
    }

    /**
     * Display the specified package
     */
    public function show(Package $package)
    {
        $package->loadCount('customers');

        return Inertia::render('Packages/Show', [
            'package' => $package,
        ]);
    }

    /**
     * Show the form for editing the specified package
     */
    public function edit(Package $package)
    {
        $routers = \App\Models\Router::with('profiles')
            ->where('is_active', true)
            ->get()
            ->map(function ($router) {
                return [
                    'id' => $router->id,
                    'name' => $router->name,
                    'profiles' => $router->profiles->map(fn($p) => [
                        'name' => $p->name,
                        'bandwidth' => $p->bandwidth,
                        'rate_limit' => $p->rate_limit,
                    ])
                ];
            });

        return Inertia::render('Packages/Edit', [
            'package' => $package,
            'routers' => $routers
        ]);
    }

    /**
     * Update the specified package
     */
    public function update(Request $request, Package $package)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'router_id' => 'required|exists:routers,id', // STRICT
            'price' => 'required|numeric|min:0',
            'bandwidth_label' => 'required|string|max:255',
            'mikrotik_profile' => 'nullable|string|max:255',
        ]);

        $package->update($validated);

        // TODO: Log this change with spatie/laravel-activitylog
        // activity()->performedOn($package)->log('updated package price');

        return redirect()->route('packages.index')
            ->with('success', 'Package updated successfully.');
    }

    /**
     * Remove the specified package
     */
    public function destroy(Package $package)
    {
        // Prevent deletion if package has customers
        if ($package->customers()->count() > 0) {
            return back()->with('error', 'Cannot delete package with active customers.');
        }

        $package->delete();

        return redirect()->route('packages.index')
            ->with('success', 'Package deleted successfully.');
    }
}
