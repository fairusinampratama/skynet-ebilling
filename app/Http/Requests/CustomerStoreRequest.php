<?php

namespace App\Http\Requests;

use App\Models\Package;
use App\Models\RouterProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\Support\Str;

class CustomerStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'nik' => ['nullable', 'string', 'max:20'],
            'pppoe_user' => ['required', 'string', 'max:255', Rule::unique('customers', 'pppoe_user')],
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'router_id' => ['nullable', 'integer', 'exists:routers,id'],
            'status' => ['required', Rule::in(['pending_installation', 'active', 'isolated', 'terminated'])],
            'geo_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'geo_long' => ['nullable', 'numeric', 'between:-180,180'],
            'ktp_photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:2048'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validatePackageRouterConsistency($validator);
            },
        ];
    }

    private function validatePackageRouterConsistency(Validator $validator): void
    {
        $package = Package::find($this->integer('package_id'));

        if (! $package || ! $package->router_id) {
            $validator->errors()->add('package_id', 'The selected package must be assigned to a router profile.');

            return;
        }

        if ($this->isFiveMProfile($package->mikrotik_profile)) {
            $validator->errors()->add('package_id', 'The selected package uses a retired 5M profile.');

            return;
        }

        $profileExists = RouterProfile::where('router_id', $package->router_id)
            ->where('name', $package->mikrotik_profile)
            ->exists();

        if (! $profileExists) {
            $validator->errors()->add('package_id', 'The selected package profile is not synced for its router.');

            return;
        }

        $routerId = $this->input('router_id');
        $requiresRouter = in_array($this->input('status'), ['active', 'isolated'], true);

        if ($requiresRouter && blank($routerId)) {
            $validator->errors()->add('router_id', 'Active and isolated customers require a router.');

            return;
        }

        if (filled($routerId) && (int) $routerId !== (int) $package->router_id) {
            $validator->errors()->add('router_id', 'The selected router must match the package router.');
        }
    }

    private function isFiveMProfile(?string $profile): bool
    {
        $profile = Str::lower(trim((string) $profile));

        return $profile === '5' || Str::startsWith($profile, '5m');
    }
}
