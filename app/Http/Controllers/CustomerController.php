<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Area;
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
            'area',
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

        // Area filter
        if ($request->has('area_id') && $request->area_id) {
            $query->where('area_id', $request->area_id);
        }

        // Sorting
        $sortField = $request->get('sort', 'join_date');
        $sortDirection = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $limit = $request->input('limit', 20);
        $customers = $query->paginate($limit)->withQueryString();

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'filters' => $request->only(['search', 'status', 'package_id', 'area_id', 'sort', 'direction', 'limit']),
            'packages' => Package::all(), // For filter dropdown
            'areas' => Area::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Show the form for creating a new customer
     */
    public function create()
    {
        return Inertia::render('Customers/Create', [
            'packages' => Package::select('id', 'name', 'price')->get(),
            'areas' => Area::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Store a newly created customer in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'required|string',
            'phone' => 'nullable|string|max:20',
            'nik' => 'nullable|string|max:20',
            'pppoe_user' => 'required|string|unique:customers,pppoe_user',
            'package_id' => 'required|exists:packages,id',
            'area_id' => 'nullable|exists:areas,id',
            'status' => 'required|in:pending_installation,active,isolated,terminated',
            'geo_lat' => 'nullable|numeric|between:-90,90',
            'geo_long' => 'nullable|numeric|between:-180,180',
            'ktp_photo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        // Auto-generate join date if not provided
        $validated['join_date'] = now();

        // Handle KTP photo upload
        if ($request->hasFile('ktp_photo')) {
            $file = $request->file('ktp_photo');
            $filename = 'customer-' . uniqid() . '.' . $file->extension();
            $path = $file->storeAs('ktp/' . now()->format('Y/m'), $filename, 'public');
            $validated['ktp_photo_url'] = $path; // Store path in mapped URL column
        }

        $customer = Customer::create($validated);

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
            'area', 
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
            'packages' => Package::select('id', 'name', 'price')->get(),
            'areas' => Area::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Update the specified customer
     */
    public function update(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'package_id' => 'required|exists:packages,id',
            'area_id' => 'nullable|exists:areas,id',
            'address' => 'required|string',
            'phone' => 'nullable|string',
            'nik' => 'nullable|string',
            'status' => 'required|in:pending_installation,active,isolated,terminated',
            'geo_lat' => 'nullable|numeric|between:-90,90',
            'geo_long' => 'nullable|numeric|between:-180,180',
            'ktp_photo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        // Handle KTP photo upload
        if ($request->hasFile('ktp_photo')) {
            // Delete old file if exists and is a local path (not a full URL)
            if ($customer->ktp_photo_url && !filter_var($customer->ktp_photo_url, FILTER_VALIDATE_URL)) {
                \Storage::disk('public')->delete($customer->ktp_photo_url);
            }
            
            $file = $request->file('ktp_photo');
            $filename = 'customer-' . $customer->id . '-' . uniqid() . '.' . $file->extension();
            $path = $file->storeAs('ktp/' . now()->format('Y/m'), $filename, 'public');
            
            $validated['ktp_photo_url'] = $path;
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
        if ($customer->status === 'active') {
            return back()->with('error', 'Cannot delete an active customer. Please terminate service first.');
        }

        $customer->delete();

        return redirect()->route('customers.index')
            ->with('success', 'Customer deleted successfully.');
    }
    
    /**
     * Isolate the customer (block internet - Manual Mode)
     */
    public function isolate(Customer $customer)
    {
        $customer->update(['status' => 'isolated']);
        
        return back()->with('success', 'Customer status set to ISOLATED.');
    }

    /**
     * Reconnect the customer (restore internet - Manual Mode)
     */
    public function reconnect(Customer $customer)
    {
        $customer->update(['status' => 'active']);

        return back()->with('success', 'Customer status set to ACTIVE.');
    }
}
