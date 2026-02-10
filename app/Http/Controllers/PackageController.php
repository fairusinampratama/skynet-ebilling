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
    /**
     * Display a listing of packages
     */
    public function index(Request $request)
    {
        $limit = $request->input('limit', 20);
        
        $packages = Package::withCount('customers')
                          ->orderBy('price', 'asc')
                          ->paginate($limit)
                          ->withQueryString();

        return Inertia::render('Packages/Index', [
            'packages' => $packages,
        ]);
    }

    /**
     * Show the form for creating a new package
     */
    public function create()
    {
        return Inertia::render('Packages/Create');
    }

    /**
     * Store a newly created package
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
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
        return Inertia::render('Packages/Edit', [
            'package' => $package,
        ]);
    }

    /**
     * Update the specified package
     */
    public function update(Request $request, Package $package)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
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
