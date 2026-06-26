<?php

namespace App\Services;

use App\Contracts\FileUploadServiceInterface;
use App\Enums\UserRole;
use App\Models\Church;
use App\Models\ChurchApplication;
use App\Models\User;
use App\Notifications\ApplicationApprovedNotification;
use App\Notifications\ApplicationRejectedNotification;
use App\Notifications\NewChurchApplicationNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChurchApplicationService
{
    public function __construct(
        private readonly FileUploadServiceInterface $fileUploadService,
        private readonly AuditService $auditService,
    ) {}

    public function submit(array $data, ?UploadedFile $frontId, ?UploadedFile $backId, string $email, string $password, ?UploadedFile $churchPermissionDoc = null): array
    {
        return DB::transaction(function () use ($data, $frontId, $backId, $email, $password, $churchPermissionDoc) {
            $application = ChurchApplication::create([
                'church_name' => $data['church_name'],
                'priest_name' => $data['priest_name'],
                'main_servant_name' => $data['main_servant_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'],
                'contact_email' => $email,
                'status' => 'pending',
            ]);

            $idType = $data['id_type'] ?? 'national_id';

            if ($idType === 'national_id') {
                if ($frontId) {
                    $path = $this->fileUploadService->uploadIdImage($frontId, (string) $application->id);
                    $application->update(['front_id_path' => $path]);
                }

                if ($backId) {
                    $path = $this->fileUploadService->uploadIdImage($backId, (string) $application->id);
                    $application->update(['back_id_path' => $path]);
                }
            } elseif ($idType === 'church_permission' && $churchPermissionDoc) {
                $path = $this->fileUploadService->uploadDocumentFile($churchPermissionDoc, (string) $application->id);
                $application->update(['church_permission_doc_path' => $path]);
            }

            $user = User::create([
                'church_application_id' => $application->id,
                'name' => $data['priest_name'],
                'email' => $email,
                'password' => Hash::make($password),
                'role' => UserRole::Admin,
                'application_status' => 'pending',
                'is_active' => true,
            ]);

            $this->auditService->log(
                action: 'church_application_submitted',
                resourceType: 'church_application',
                resourceId: $application->id,
                newValues: ['church_name' => $data['church_name'], 'status' => 'pending'],
            );

            try {
                $platformAdmins = User::where('role', UserRole::PlatformAdmin)->get();
                foreach ($platformAdmins as $admin) {
                    $admin->notify(new NewChurchApplicationNotification($application));
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to notify platform admins', [
                    'application_id' => $application->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return [
                'application' => $application,
                'user' => $user,
            ];
        });
    }

    public function uploadIdImage(ChurchApplication $application, string $side, UploadedFile $image): ChurchApplication
    {
        $field = $side === 'front' ? 'front_id_path' : 'back_id_path';
        $path = $this->fileUploadService->uploadIdImage($image, (string) $application->id);
        $application->update([$field => $path]);
        return $application->fresh();
    }

    public function approve(ChurchApplication $application, User $platformAdmin, ?string $notes = null): Church
    {
        if ($application->status !== 'pending') {
            throw ValidationException::withMessages([
                'application' => ['This application has already been ' . $application->status . '.'],
            ]);
        }

        return DB::transaction(function () use ($application, $platformAdmin, $notes) {
            $oldStatus = $application->status;

            $church = Church::create([
                'name' => $application->church_name,
                'slug' => Str::slug($application->church_name) . '-' . Str::random(6),
                'priest_name' => $application->priest_name,
                'main_servant_name' => $application->main_servant_name,
                'priest_phone' => $application->priest_phone ?? $application->phone,
                'phone' => $application->phone,
                'address' => $application->address,
                'contact_email' => $application->contact_email,
                'is_active' => true,
                'is_suspended' => false,
            ]);

            $application->update([
                'status' => 'approved',
                'reviewed_by' => $platformAdmin->id,
                'reviewed_at' => now(),
                'admin_notes' => $notes ? ($application->admin_notes ? $application->admin_notes . "\n" . $notes : $notes) : $application->admin_notes,
            ]);

            $admin = User::where('church_application_id', $application->id)->first();

            if ($admin) {
                $admin->update([
                    'church_id' => $church->id,
                    'application_status' => 'approved',
                    'role' => UserRole::Admin,
                    'is_active' => true,
                    'email_verified_at' => $admin->email_verified_at ?? now(),
                ]);
            }

            $church->update(['contact_email' => $application->contact_email]);

            $this->auditService->log(
                action: 'church_application_approved',
                resourceType: 'church_application',
                resourceId: $application->id,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => 'approved', 'church_id' => $church->id],
                userId: $platformAdmin->id,
            );

            if ($admin) {
                try {
                    $admin->notify(new ApplicationApprovedNotification($application, $admin, $church->name));
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Failed to notify applicant of approval', [
                        'application_id' => $application->id,
                        'user_id' => $admin->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $church;
        });
    }

    public function reject(ChurchApplication $application, User $platformAdmin, string $reason): ChurchApplication
    {
        if ($application->status !== 'pending') {
            throw ValidationException::withMessages([
                'application' => ['This application has already been ' . $application->status . '.'],
            ]);
        }

        return DB::transaction(function () use ($application, $platformAdmin, $reason) {
            $oldStatus = $application->status;

            $application->update([
                'status' => 'rejected',
                'reviewed_by' => $platformAdmin->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            User::where('church_application_id', $application->id)->update([
                'application_status' => 'rejected',
            ]);

            $this->auditService->log(
                action: 'church_application_rejected',
                resourceType: 'church_application',
                resourceId: $application->id,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => 'rejected', 'rejection_reason' => $reason],
                userId: $platformAdmin->id,
            );

            $applicant = User::where('church_application_id', $application->id)->first();
            if ($applicant) {
                try {
                    $applicant->notify(new ApplicationRejectedNotification($application, $applicant, $reason));
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Failed to notify applicant of rejection', [
                        'application_id' => $application->id,
                        'user_id' => $applicant->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $application->fresh();
        });
    }

    public function listApplications(?string $status = null, int $perPage = 15)
    {
        $query = ChurchApplication::query();

        if ($status && in_array($status, ['pending', 'approved', 'rejected'])) {
            $query->where('status', $status);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }
}
