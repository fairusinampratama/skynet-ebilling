<?php

namespace App\Http\Controllers;

use App\Models\Area;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AreaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Area::query();

        if ($request->search) {
            $query->where('name', 'like', "%{$request->search}%")
                  ->orWhere('code', 'like', "%{$request->search}%");
        }

        $areas = $query->withCount('customers')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Areas/Index', [
            'areas' => $areas,
            'filters' => [
                'search' => $request->search,
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Areas/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:areas,name',
            'code' => 'required|string|max:255|unique:areas,code',
        ]);

        Area::create($validated);

        return redirect()->route('areas.index')
            ->with('success', 'Area created successfully.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Area $area)
    {
        return Inertia::render('Areas/Edit', [
            'area' => $area,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Area $area)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:areas,name,' . $area->id,
            'code' => 'required|string|max:255|unique:areas,code,' . $area->id,
        ]);

        $area->update($validated);

        return redirect()->route('areas.index')
            ->with('success', 'Area updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Area $area)
    {
        if ($area->customers()->exists()) {
            return back()->with('error', 'Cannot delete area with associated customers.');
        }

        $area->delete();

        return redirect()->route('areas.index')
            ->with('success', 'Area deleted successfully.');
    }
}
