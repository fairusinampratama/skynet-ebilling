<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
}
