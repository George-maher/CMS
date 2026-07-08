<?php

namespace App\Http\Requests;

use App\Models\ChurchApplication;
use App\Rules\NotPlaceholder;
use App\Rules\PhoneRule;
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
        $email = $this->input('email');
        $existingApplication = ChurchApplication::where('contact_email', $email)->first();
        $isUpdate = $existingApplication !== null;

        $userUniqueRule = $isUpdate
            ? Rule::unique('users', 'email')->where(function ($query) use ($existingApplication) {
                $query->where('church_application_id', '!=', $existingApplication->id);
            })
            : 'unique:users,email';

        return [
            'church_name' => ['required', 'string', 'max:255', new NotPlaceholder],
            'service_name' => ['nullable', 'string', 'max:255'],
            'priest_name' => ['required', 'string', 'max:255', new NotPlaceholder],
            'main_servant_name' => ['required', 'string', 'max:255', new NotPlaceholder],
            'phone' => ['required', new PhoneRule, new NotPlaceholder],
            'email' => ['required', 'email', 'max:255', $userUniqueRule, new NotPlaceholder],
            'password' => [$isUpdate ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
            'address' => ['required', 'string', 'max:1000', new NotPlaceholder],
            'id_type' => ['required', 'string', Rule::in(['national_id', 'church_permission'])],
            'front_id' => [
                Rule::requiredIf(fn () => $this->input('id_type') === 'national_id' && !$isUpdate),
                'nullable', 'image', 'mimes:jpg,jpeg,png', 'max:' . config('supabase-storage.max_image_size', 5120),
            ],
            'back_id' => [
                Rule::requiredIf(fn () => $this->input('id_type') === 'national_id' && !$isUpdate),
                'nullable', 'image', 'mimes:jpg,jpeg,png', 'max:' . config('supabase-storage.max_image_size', 5120),
            ],
            'church_permission_doc' => [
                Rule::requiredIf(fn () => $this->input('id_type') === 'church_permission' && !$isUpdate),
                'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:' . config('supabase-storage.max_document_size', 10240),
            ],
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
            'email.unique' => 'This email is already registered to a different account. Please use a different email or log in.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('password') && empty($this->input('password'))) {
            $this->request->remove('password');
            $this->request->remove('password_confirmation');
        }
    }
}
