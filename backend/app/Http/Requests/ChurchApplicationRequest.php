<?php

namespace App\Http\Requests;

use App\Rules\NotPlaceholder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChurchApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'church_name' => ['required', 'string', 'max:255', new NotPlaceholder],
            'priest_name' => ['required', 'string', 'max:255', new NotPlaceholder],
            'main_servant_name' => ['required', 'string', 'max:255', new NotPlaceholder],
            'phone' => ['required', 'digits:11', 'regex:/^[0-9]{11}$/', new NotPlaceholder],
            'email' => ['required', 'email', 'max:255', 'unique:users,email', new NotPlaceholder],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'address' => ['required', 'string', 'max:1000', new NotPlaceholder],
            'id_type' => ['required', 'string', Rule::in(['national_id', 'church_permission'])],
            'front_id' => ['required_if:id_type,national_id', 'image', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'back_id' => ['required_if:id_type,national_id', 'image', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'church_permission_doc' => ['required_if:id_type,church_permission', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'front_id.required_if' => 'Please upload the front of your National ID card.',
            'back_id.required_if' => 'Please upload the back of your National ID card.',
            'church_permission_doc.required_if' => 'Please upload your Church Permission document.',
            'id_type.required' => 'Please select an ID verification type.',
            'id_type.in' => 'Invalid verification type selected.',
            'email.unique' => 'This email is already registered. If you already submitted an application, please check your email for updates or try logging in.',
        ];
    }
}
