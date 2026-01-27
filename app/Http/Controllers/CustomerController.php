<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Package;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CustomerController extends Controller
{
    /**
     * Display a listing of customers with search and filters
     */
    public function index(Request $request)
    {
        $query = Customer::with([
            'package',
            'invoices' => function($q) {
                $q->where('status', 'unpaid')->orderBy('due_date', 'asc')->limit(1);
            }
        ]);

        // Search functionality
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('pppoe_user', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%")
                  ->orWhere('internal_id', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Package filter
        if ($request->has('package_id') && $request->package_id) {
            $query->where('package_id', $request->package_id);
        }

        // Sorting
        $sortField = $request->get('sort', 'created_at');
        $sortDirection = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $customers = $query->paginate(50)->withQueryString();

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'filters' => $request->only(['search', 'status', 'package_id', 'sort', 'direction']),
            'packages' => Package::all(), // For filter dropdown
        ]);
    }

    /**
     * Show the form for creating a new customer
     */
    public function create()
    {
        return Inertia::render('Customers/Create', [
            'packages' => Package::all(),
        ]);
    }

    /**
     * Store a newly created customer in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'internal_id' => 'nullable|string|max:255',
            'address' => 'required|string',
            'phone' => 'nullable|string|max:20',
            'nik' => 'nullable|string|max:20',
            'pppoe_user' => 'required|string|unique:customers,pppoe_user',
            'pppoe_pass' => 'required|string',
            'package_id' => 'required|exists:packages,id',
            'status' => 'required|in:active,suspended,isolated,offboarding',
            'geo_lat' => 'nullable|numeric|between:-90,90',
            'geo_long' => 'nullable|numeric|between:-180,180',
        ]);

        // Auto-generate join date if not provided
        $validated['join_date'] = now();

        Customer::create($validated);

        return redirect()->route('customers.index')
            ->with('success', 'Customer created successfully.');
    }

    /**
     * Display the specified customer.
     */
    public function show(Customer $customer)
    {
        $customer->load([
            'package', 
            'invoices' => function($q) {
                $q->latest('period'); 
            },
            'invoices.transactions'
        ]);

        return Inertia::render('Customers/Show', [
            'customer' => $customer,
        ]);
    }

    /**
     * Show the form for editing the customer
     */
    public function edit(Customer $customer)
    {
        return Inertia::render('Customers/Edit', [
            'customer' => $customer,
            'packages' => Package::all(),
        ]);
    }

    /**
     * Update the specified customer
     */
    public function update(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'internal_id' => 'nullable|string|max:255',
            'package_id' => 'required|exists:packages,id',
            'address' => 'required|string',
            'phone' => 'nullable|string',
            'nik' => 'nullable|string',
            'pppoe_user' => 'required|string|unique:customers,pppoe_user,' . $customer->id,
            'pppoe_pass' => 'nullable|string', // Optional password update
            'status' => 'required|in:active,suspended,isolated,offboarding',
            'geo_lat' => 'nullable|numeric|between:-90,90',
            'geo_long' => 'nullable|numeric|between:-180,180',
        ]);

        // Only update password if a new one is provided
        if ($request->filled('pppoe_pass')) {
            // The model cast will automatically encrypt this
            $validated['pppoe_pass'] = $request->pppoe_pass;
        } else {
            unset($validated['pppoe_pass']);
        }

        $customer->update($validated);

        return redirect()->route('customers.index')
            ->with('success', 'Customer updated successfully.');
    }

    /**
     * Remove the specified customer
     */
    public function destroy(Customer $customer)
    {
        $customer->delete();

        return redirect()->route('customers.index')
            ->with('success', 'Customer deleted successfully.');
    }
}
