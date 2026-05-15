<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Inertia\Inertia;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $users = User::query()
            ->with('areas:id,name,code')
            ->when($request->search, function ($query, $search) {
                $query->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderByRaw("FIELD(role, 'superadmin', 'admin')")
            ->orderBy('name')
            ->paginate($request->input('limit', 20))
            ->withQueryString();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => $request->only(['search', 'limit']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Users/Create', [
            'areas' => Area::select('id', 'name', 'code')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role' => ['required', Rule::in(['superadmin', 'admin'])],
            'area_ids' => ['array'],
            'area_ids.*' => ['integer', 'exists:areas,id'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'email_verified_at' => now(),
        ]);

        $this->syncAreas($user, $validated['area_ids'] ?? []);

        return redirect()->route('users.index')->with('success', 'User created successfully.');
    }

    public function edit(User $user)
    {
        $user->load('areas:id,name,code');

        return Inertia::render('Users/Edit', [
            'managedUser' => $user,
            'areas' => Area::select('id', 'name', 'code')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
            'role' => ['required', Rule::in(['superadmin', 'admin'])],
            'area_ids' => ['array'],
            'area_ids.*' => ['integer', 'exists:areas,id'],
        ]);

        if ($user->isSuperAdmin() && $validated['role'] !== 'superadmin' && $this->superAdminCount() <= 1) {
            return back()->with('error', 'Cannot demote the last superadmin.');
        }

        $payload = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
        ];

        if (! empty($validated['password'])) {
            $payload['password'] = Hash::make($validated['password']);
        }

        $user->update($payload);
        $this->syncAreas($user, $validated['area_ids'] ?? []);

        return redirect()->route('users.index')->with('success', 'User updated successfully.');
    }

    public function destroy(User $user)
    {
        if ($user->isSuperAdmin() && $this->superAdminCount() <= 1) {
            return back()->with('error', 'Cannot delete the last superadmin.');
        }

        if ($user->is(auth()->user())) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $user->delete();

        return redirect()->route('users.index')->with('success', 'User deleted successfully.');
    }

    private function syncAreas(User $user, array $areaIds): void
    {
        if ($user->isAdmin()) {
            $user->areas()->sync($areaIds);
            return;
        }

        $user->areas()->detach();
    }

    private function superAdminCount(): int
    {
        return User::where('role', 'superadmin')->count();
    }
}
