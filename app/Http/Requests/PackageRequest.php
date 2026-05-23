<?php

namespace App\Http\Requests;

use App\Models\RouterProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'router_id' => ['required', 'integer', 'exists:routers,id'],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'mikrotik_profile' => ['required', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $profileExists = RouterProfile::where('router_id', $this->integer('router_id'))
                    ->where('name', $this->string('mikrotik_profile')->toString())
                    ->exists();

                if (! $profileExists) {
                    $validator->errors()->add(
                        'mikrotik_profile',
                        'The selected MikroTik profile is not synced for this router.'
                    );
                }
            },
        ];
    }
}
