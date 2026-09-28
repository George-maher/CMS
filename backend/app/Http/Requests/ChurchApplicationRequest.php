<?php

namespace App\Http\Requests;

use App\Rules\NotPlaceholder;
use App\Rules\PhoneRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Public church application submission.
 *
 * ENUMERATION SAFETY
 * ------------------
 * The rule set is deliberately **independent of whether the submitted address
 * already has an application on file**. An earlier version branched on
 * `ChurchApplication::where('contact_email', $email)->exists()` to make
 * `password` and the identity documents optional for known addresses, which
 * meant the *validation response itself* told an anonymous caller which
 * addresses were already registered — a public account-enumeration oracle that
 * the service layer's uniform response could not close.
 *
 * The only caller-attribute that may relax the requirements is a **proven
 * applicant session**: the caller is authenticated as the user whose
 * `church_application_id` belongs to the application carrying the submitted
 * address. That is derived from the authenticated principal, never from the
 * submitted address alone, so an anonymous caller sees exactly the same
 * requirements for every address.
 *
 * Ownership by password is still supported and is the recommended path for a
 * returning applicant: they supply their password, satisfy the uniform
 * requirements, and the service then verifies that password before allowing any
 * update.
 */
class ChurchApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var int $maxImageSize */
        $maxImageSize = config('supabase-storage.max_image_size', 5120);
        /** @var int $maxDocSize */
        $maxDocSize = config('supabase-storage.max_document_size', 10240);

        $isProvenApplicant = $this->callerIsProvenApplicant();

        return [
            'church_name' => ['required', 'string', 'max:255', new NotPlaceholder],
            'service_name' => ['nullable', 'string', 'max:255'],
            'priest_name' => ['required', 'string', 'max:255', new NotPlaceholder],
            'main_servant_name' => ['required', 'string', 'max:255', new NotPlaceholder],
            'phone' => ['required', new PhoneRule, new NotPlaceholder],
            // No uniqueness rule here: whether this address already belongs to an
            // account is exactly the fact that must not be observable here. The
            // service suppresses such submissions and acknowledges them
            // identically to an accepted one.
            'email' => ['required', 'email', 'max:255', new NotPlaceholder],
            'password' => [
                Rule::requiredIf(static fn (): bool => ! $isProvenApplicant),
                'nullable',
                'string',
                PasswordRule::min(8),
                'confirmed',
            ],
            'address' => ['required', 'string', 'max:1000', new NotPlaceholder],
            'id_type' => ['required', 'string', Rule::in(['national_id', 'church_permission'])],
            'front_id' => [
                Rule::requiredIf(fn (): bool => $this->input('id_type') === 'national_id' && ! $isProvenApplicant),
                'nullable',
                'image',
                'mimes:jpg,jpeg,png',
                'max:'.$maxImageSize,
            ],
            'back_id' => [
                Rule::requiredIf(fn (): bool => $this->input('id_type') === 'national_id' && ! $isProvenApplicant),
                'nullable',
                'image',
                'mimes:jpg,jpeg,png',
                'max:'.$maxImageSize,
            ],
            'church_permission_doc' => [
                Rule::requiredIf(fn (): bool => $this->input('id_type') === 'church_permission' && ! $isProvenApplicant),
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:'.$maxDocSize,
            ],
        ];
    }

    /**
     * Whether the caller is authenticated as the applicant of the application
     * that carries the submitted address.
     *
     * Derived from the authenticated principal plus the address, never from the
     * address alone.
     */
    private function callerIsProvenApplicant(): bool
    {
        $user = $this->user();

        if ($user === null || $user->church_application_id === null) {
            return false;
        }

        /** @var string|null $email */
        $email = $this->input('email');

        if (! is_string($email) || $email === '') {
            return false;
        }

        return DB::table('church_applications')
            ->where('id', $user->church_application_id)
            ->where('contact_email', strtolower(trim($email)))
            ->exists();
    }

    public function messages(): array
    {
        return [
            'front_id.required_if' => 'Please upload the front of your National ID card.',
            'back_id.required_if' => 'Please upload the back of your National ID card.',
            'church_permission_doc.required_if' => 'Please upload your Church Permission document.',
            'id_type.required' => 'Please select an ID verification type.',
            'id_type.in' => 'Invalid verification type selected.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $email = $this->input('email');
            if (is_string($email)) {
                $this->merge([
                    'email' => strtolower(trim($email)),
                ]);
            }
        }

        if ($this->has('password') && empty($this->input('password'))) {
            $this->request->remove('password');
            $this->request->remove('password_confirmation');
        }
    }
}
