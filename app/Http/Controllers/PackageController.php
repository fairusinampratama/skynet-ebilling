<?php

namespace App\Http\Controllers;

use App\Http\Requests\PackageRequest;
use App\Models\Package;
use App\Models\Router;
use App\Models\RouterProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PackageController extends Controller
{
    /**
     * Display a listing of packages
     */
    public function index(Request $request)
    {
        $limit = $request->input('limit', 20);
        $viewFilter = (string) $request->input('view', 'active');
        $view = str_contains($viewFilter, 'archive') ? 'archive' : 'active';
        $routerId = $this->firstIntegerFilter($request->input('router_id'));

        if ($view === 'active' && ! $routerId) {
            $routerId = $this->defaultRouterId();
        }

        $allowedSorts = ['name', 'price', 'mikrotik_profile', 'customers_count', 'created_at'];
        $sort = in_array($request->input('sort'), $allowedSorts, true) ? $request->input('sort') : 'price';
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';

        $packages = Package::with('router:id,name')
            ->withCount('customers')
            ->when($routerId, fn ($query) => $query->where('router_id', $routerId))
            ->when($view === 'active', fn ($query) => $this->assignablePackages($query))
            ->when($view === 'archive', fn ($query) => $this->archivedPackages($query))
            ->when($request->search, function ($query, $search) {
                $query->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('mikrotik_profile', 'like', "%{$search}%")
                        ->orWhere('rate_limit', 'like', "%{$search}%");
                });
            })
            ->orderBy($sort, $direction)
            ->paginate($limit)
            ->through(fn (Package $package) => $this->packagePayload($package))
            ->withQueryString();

        return Inertia::render('Packages/Index', [
            'packages' => $packages,
            'routers' => Router::select('id', 'name')->orderBy('name')->get(),
            'filters' => [
                'search' => $request->search,
                'limit' => $limit,
                'sort' => $sort,
                'direction' => $direction,
                'router_id' => $routerId ? (string) $routerId : null,
                'view' => $view,
            ],
        ]);
    }

    /**
     * Show the form for creating a new package
     */
    public function create()
    {
        return Inertia::render('Packages/Create', [
            'routers' => $this->routersForPackageForms(),
        ]);
    }

    /**
     * Store a newly created package
     */
    public function store(PackageRequest $request)
    {
        $validated = $request->validated();
        $validated['rate_limit'] = $this->selectedProfile($validated)->rate_limit;
        $validated['code'] = $this->uniquePackageCode($validated['name']);

        Package::create($validated);

        return redirect()->route('packages.index')
            ->with('success', 'Package created successfully.');
    }

    /**
     * Display the specified package
     */
    public function show(Package $package)
    {
        $package->load('router:id,name')->loadCount('customers');

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
            'package' => $package->load('router:id,name'),
            'routers' => $this->routersForPackageForms(),
        ]);
    }

    /**
     * Update the specified package
     */
    public function update(PackageRequest $request, Package $package)
    {
        $validated = $request->validated();
        $validated['rate_limit'] = $this->selectedProfile($validated)->rate_limit;

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
        if ($package->customers()->ebilling()->count() > 0) {
            return back()->with('error', 'Cannot delete package with active customers.');
        }

        $package->delete();

        return redirect()->route('packages.index')
            ->with('success', 'Package deleted successfully.');
    }

    public function byRouter(Router $router)
    {
        $query = Package::select('id', 'name', 'price', 'router_id', 'mikrotik_profile', 'rate_limit')
            ->where('router_id', $router->id);

        $this->assignablePackages($query);

        return response()->json($query->orderBy('name')->get());
    }

    private function routersForPackageForms()
    {
        return Router::select('id', 'name')
            ->with(['profiles' => fn ($query) => $query
                ->select('id', 'router_id', 'name', 'rate_limit', 'bandwidth')
                ->orderBy('name')])
            ->orderBy('name')
            ->get();
    }

    private function selectedProfile(array $validated): RouterProfile
    {
        return RouterProfile::where('router_id', $validated['router_id'])
            ->where('name', $validated['mikrotik_profile'])
            ->firstOrFail();
    }

    private function uniquePackageCode(string $name): string
    {
        $base = Str::upper(Str::slug($name));
        $base = $base !== '' ? $base : 'PACKAGE';
        $candidate = $base;
        $suffix = 1;

        while (Package::where('code', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }

    private function defaultRouterId(): ?int
    {
        return Router::query()
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('packages')
                    ->whereColumn('packages.router_id', 'routers.id')
                    ->whereExists(function ($profileQuery) {
                        $profileQuery->select(DB::raw(1))
                            ->from('router_profiles')
                            ->whereColumn('router_profiles.router_id', 'packages.router_id')
                            ->whereColumn('router_profiles.name', 'packages.mikrotik_profile');
                    })
                    ->where(fn ($packageQuery) => $this->notFiveMLike($packageQuery, 'packages.mikrotik_profile'));
            })
            ->orderBy('name')
            ->value('id');
    }

    private function firstIntegerFilter(mixed $value): ?int
    {
        $value = is_array($value) ? reset($value) : $value;
        $value = explode(',', (string) $value)[0] ?? null;
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return $value ? (int) $value : null;
    }

    private function assignablePackages(Builder $query): Builder
    {
        return $query
            ->whereNotNull('router_id')
            ->whereExists(function ($profileQuery) {
                $profileQuery->select(DB::raw(1))
                    ->from('router_profiles')
                    ->whereColumn('router_profiles.router_id', 'packages.router_id')
                    ->whereColumn('router_profiles.name', 'packages.mikrotik_profile');
            })
            ->where(fn ($packageQuery) => $this->notFiveMLike($packageQuery, 'packages.mikrotik_profile'));
    }

    private function archivedPackages(Builder $query): Builder
    {
        return $query->where(function ($archiveQuery) {
            $archiveQuery
                ->whereNull('router_id')
                ->orWhere(fn ($profileQuery) => $this->fiveMLike($profileQuery, 'packages.mikrotik_profile'))
                ->orWhereNotExists(function ($profileQuery) {
                    $profileQuery->select(DB::raw(1))
                        ->from('router_profiles')
                        ->whereColumn('router_profiles.router_id', 'packages.router_id')
                        ->whereColumn('router_profiles.name', 'packages.mikrotik_profile');
                });
        });
    }

    private function packagePayload(Package $package): array
    {
        $profileExists = $this->packageProfileExists($package);
        $archiveReason = $this->archiveReason($package, $profileExists);

        return array_merge($package->toArray(), [
            'router' => $package->router,
            'customers_count' => $package->customers_count,
            'profile_exists' => $profileExists,
            'is_assignable' => $archiveReason === null,
            'archive_reason' => $archiveReason,
        ]);
    }

    private function archiveReason(Package $package, bool $profileExists): ?string
    {
        if (! $package->router_id) {
            return 'Global';
        }

        if ($this->isFiveMProfile($package->mikrotik_profile)) {
            return '5M Retired';
        }

        if (! $profileExists) {
            return 'Invalid Profile';
        }

        return null;
    }

    private function packageProfileExists(Package $package): bool
    {
        if (! $package->router_id || ! $package->mikrotik_profile) {
            return false;
        }

        return RouterProfile::where('router_id', $package->router_id)
            ->where('name', $package->mikrotik_profile)
            ->exists();
    }

    private function fiveMLike($query, string $column)
    {
        return $query
            ->whereRaw("LOWER(TRIM({$column})) = '5'")
            ->orWhereRaw("LOWER(TRIM({$column})) LIKE '5m%'");
    }

    private function notFiveMLike($query, string $column)
    {
        return $query
            ->whereRaw("LOWER(TRIM({$column})) <> '5'")
            ->whereRaw("LOWER(TRIM({$column})) NOT LIKE '5m%'");
    }

    private function isFiveMProfile(?string $profile): bool
    {
        $profile = Str::lower(trim((string) $profile));

        return $profile === '5' || Str::startsWith($profile, '5m');
    }
}
