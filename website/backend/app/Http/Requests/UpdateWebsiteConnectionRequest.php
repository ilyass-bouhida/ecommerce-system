<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWebsiteConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'website_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url:http,https'],
            'api_base_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url:http,https'],
            'api_key' => ['sometimes', 'nullable', 'string', 'max:4096'],
            // '__REMOVE__' explicitly clears; absent/null/'' keeps existing.
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'max:4096', function ($attribute, $value, $fail) {
                if ($value === null || $value === '' || $value === '__REMOVE__') {
                    return;
                }
                if (mb_strlen($value) < 16) {
                    $fail('The webhook secret must be at least 16 characters.');
                }
            }],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
