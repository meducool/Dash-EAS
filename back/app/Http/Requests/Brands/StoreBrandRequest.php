<?php

namespace App\Http\Requests\Brands;

use Illuminate\Foundation\Http\FormRequest;

class StoreBrandRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'name' => 'required|string|max:255|unique:brands,name',
            'code' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'logo' => 'nullable|image|max:2048',
            'is_active' => 'nullable',
        ];
    }
}
