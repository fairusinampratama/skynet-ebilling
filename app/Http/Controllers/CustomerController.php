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
        $sortField = $request->get('sort', 'join_date');
        $sortDirection = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $limit = $request->input('limit', 25);
        $customers = $query->paginate($limit)->withQueryString();

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'filters' => $request->only(['search', 'status', 'package_id', 'sort', 'direction', 'limit']),
            'packages' => Package::all(), // For filter dropdown
        ]);
    }

    /**
     * Show the form for creating a new customer
     */
    public function create()
    {
        return Inertia::render('Customers/Create', [
            'packages' => Package::with('router:id,name')->get(), // Eager load router for clarity if needed
            'routers' => \App\Models\Router::where('is_active', true)->select('id', 'name')->get(),
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
            'router_id' => 'required|exists:routers,id',
            'package_id' => 'required|exists:packages,id',
            'status' => 'required|in:pending_installation,active,isolated,terminated',
            'geo_lat' => 'nullable|numeric|between:-90,90',
            'geo_long' => 'nullable|numeric|between:-180,180',
            'ktp_photo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'ktp_external_url' => 'nullable|string|url',
        ]);

        // Auto-generate join date if not provided
        $validated['join_date'] = now();

        // Handle KTP photo upload
        if ($request->hasFile('ktp_photo')) {
            $file = $request->file('ktp_photo');
            $filename = 'customer-' . uniqid() . '.' . $file->extension();
            $path = $file->storeAs('ktp/' . now()->format('Y/m'), $filename, 'public');
            $validated['ktp_photo_path'] = $path;
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
            'packages' => Package::with('router:id,name')->get(),
            'routers' => \App\Models\Router::select('id', 'name')->get(), // Show all routers even if inactive, to avoid breaking existing assignments
        ]);
    }

    /**
     * Update the specified customer
     */
    public function update(Request $request, Customer $customer, \App\Services\MikrotikService $mikrotik)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'internal_id' => 'nullable|string|max:255',
            'package_id' => 'required|exists:packages,id', // Re-enabled
            'router_id' => 'required|exists:routers,id', // Added
            'address' => 'required|string',
            'phone' => 'nullable|string',
            'nik' => 'nullable|string',
            // 'pppoe_user' => 'required|string|unique:customers,pppoe_user,' . $customer->id, // Disabled
            'status' => 'required|in:pending_installation,active,isolated,terminated',
            'geo_lat' => 'nullable|numeric|between:-90,90',
            'geo_long' => 'nullable|numeric|between:-180,180',
            'ktp_photo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'ktp_external_url' => 'nullable|string|url',
        ]);

        // Handle KTP photo upload
        if ($request->hasFile('ktp_photo')) {
            // Delete old file if exists
            if ($customer->ktp_photo_path) {
                \Storage::disk('public')->delete($customer->ktp_photo_path);
            }
            
            $file = $request->file('ktp_photo');
            $filename = 'customer-' . $customer->id . '-' . uniqid() . '.' . $file->extension();
            $path = $file->storeAs('ktp/' . now()->format('Y/m'), $filename, 'public');
            
            $validated['ktp_photo_path'] = $path;
            $validated['ktp_external_url'] = null; // Clear external URL when uploading new file
        }

        // Password update logic removed - handled by NOC on router only

        // Initialize status as current, in case we block the update
        $targetStatus = $request->status;
        $currentStatus = $customer->status;

        // STRICT STATUS TRANSITION LOGIC
        if ($targetStatus !== $currentStatus && $customer->router_id) {
            try {
                $router = $customer->router;
                $username = $customer->pppoe_user;
                
                // 1. PENDING -> ACTIVE (Activation)
                if ($currentStatus === 'pending_installation' && $targetStatus === 'active') {
                    $mikrotik->connect($router);
                    $secret = $mikrotik->getPPPSecret($username);
                    $mikrotik->disconnect();

                    if (!$secret) {
                        return back()->with('error', "ACTIVATION FAILED: Username '{$username}' not found on Router {$router->name}. Please have NOC configure it first.");
                    }
                    // If secret exists, proceed.
                }

                // 2. ACTIVE -> ISOLATED (Isolation)
                if ($currentStatus === 'active' && $targetStatus === 'isolated') {
                    $mikrotik->connect($router);
                    $success = $mikrotik->isolateUser($username);
                    $mikrotik->disconnect();

                    if (!$success) {
                        return back()->with('error', "ISOLATION FAILED: Could not isolate user on Router. Check connection.");
                    }
                }

                // 3. ISOLATED -> ACTIVE (Reconnection)
                if ($currentStatus === 'isolated' && $targetStatus === 'active') {
                    $mikrotik->connect($router);
                    $success = $mikrotik->reconnectUser($username);
                    $mikrotik->disconnect();

                    if (!$success) {
                        return back()->with('error', "RECONNECT FAILED: Could not reconnect user on Router.");
                    }
                }
                
                // 4. ANY -> TERMINATED (Termination)
                if ($targetStatus === 'terminated') {
                     // We try to clean up, but we don't block termination if router is offline
                     try {
                        $mikrotik->connect($router);
                        // Kick user to ensure they are offline
                        // We don't remove the secret automatically (NOC job), but we kill the session
                        $mikrotik->kickUser($username); // Assuming helper exists, or we leave it to NOC
                        $mikrotik->disconnect();
                     } catch (\Exception $e) {
                         // Log this but don't stop the termination in App
                         \Log::warning("Termination Warning: Could not reach router to kick user {$username}");
                     }
                }

            } catch (\Exception $e) {
                return back()->with('error', 'ROUTER ERROR: ' . $e->getMessage());
            }
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
     * Isolate the customer (block internet)
     */
    public function isolate(Customer $customer, \App\Services\MikrotikService $mikrotik)
    {
        if ($customer->status !== 'active') {
            return back()->with('error', 'Only active customers can be isolated.');
        }

        if (!$customer->router_id) {
             return back()->with('error', 'Customer has no router assigned.');
        }

        try {
            $router = $customer->router;
            
            // 1. Strict Verification: Connect and Execute immediately
            $mikrotik->connect($router);
            $success = $mikrotik->isolateUser($customer->pppoe_user);
            $mikrotik->disconnect();

            if ($success) {
                // 2. Only update DB if router confirmed success
                $customer->update(['status' => 'isolated']);
                
                // Optional: Dispatch async job for logging/telemetry if needed, 
                // but main action is done here.
                
                return back()->with('success', 'VERIFIED: Customer is isolated on the router.');
            } else {
                 return back()->with('error', 'ROUTER ERROR: PPPoE User not found on the router.');
            }

        } catch (\Exception $e) {
            // 3. Catch connection/timeout errors and show them raw
            return back()->with('error', 'ISOLATION FAILED: ' . $e->getMessage());
        }
    }

    /**
     * Reconnect the customer (restore internet)
     */
    public function reconnect(Customer $customer, \App\Services\MikrotikService $mikrotik)
    {
        if ($customer->status !== 'isolated') {
            return back()->with('error', 'Only isolated customers can be reconnected.');
        }

        if (!$customer->router_id) {
             return back()->with('error', 'Customer has no router assigned.');
        }

        try {
            $router = $customer->router;
            
            $mikrotik->connect($router);
            // Default to 'default' profile or previous if stored in future
            $success = $mikrotik->reconnectUser($customer->pppoe_user, 'default');
            $mikrotik->disconnect();

            if ($success) {
                $customer->update(['status' => 'active']);
                return back()->with('success', 'VERIFIED: Internet access RESTORED on router.');
            } else {
                return back()->with('error', 'ROUTER ERROR: PPPoE User not found on the router.');
            }

        } catch (\Exception $e) {
            return back()->with('error', 'RECONNECT FAILED: ' . $e->getMessage());
        }
    }
}
