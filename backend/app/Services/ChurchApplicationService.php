<?php

namespace App\Services;

use App\Contracts\ChurchApplicationServiceInterface;
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

class ChurchApplicationService implements ChurchApplicationServiceInterface
{
    public function __construct(
        private readonly FileUploadServiceInterface $fileUploadService,
        private readonly AuditService $auditService,
    ) {}

    public function findByEmail(string $email): ?ChurchApplication
    {
        return ChurchApplication::where('contact_email', $email)->first();
    }

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function submit(array $data, ?UploadedFile $frontId, ?UploadedFile $backId, string $email, string $password, ?UploadedFile $churchPermissionDoc = null): array
    {
        $existing = $this->findByEmail($email);

        if ($existing) {
            return $this->updateExisting($existing, $data, $frontId, $backId, $churchPermissionDoc);
        }

        return $this->createNew($data, $frontId, $backId, $email, $password, $churchPermissionDoc);
    }

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    private function createNew(array $data, ?UploadedFile $frontId, ?UploadedFile $backId, string $email, string $password, ?UploadedFile $churchPermissionDoc = null): array
    {
        return DB::transaction(function () use ($data, $frontId, $backId, $email, $password, $churchPermissionDoc) {
            /** @var array<string, mixed> $data */
            /** @var string $churchName */
            $churchName = $data['church_name'];
            /** @var string|null $serviceName */
            $serviceName = $data['service_name'] ?? null;
            /** @var string $priestName */
            $priestName = $data['priest_name'];
            /** @var string|null $phone */
            $phone = $data['phone'] ?? null;
            /** @var string|null $mainServantName */
            $mainServantName = $data['main_servant_name'] ?? null;
            /** @var string $address */
            $address = $data['address'];

            $application = ChurchApplication::create([
                'church_name' => $churchName,
                'service_name' => $serviceName,
                'priest_name' => $priestName,
                'priest_phone' => $phone,
                'main_servant_name' => $mainServantName,
                'phone' => $phone,
                'address' => $address,
                'contact_email' => $email,
                'status' => 'pending',
            ]);

            $this->uploadApplicationFiles($application, $data, $frontId, $backId, $churchPermissionDoc);

            $user = User::create([
                'church_application_id' => $application->id,
                'name' => $priestName,
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
                newValues: ['church_name' => $churchName, 'status' => 'pending'],
            );

            $this->notifyPlatformAdmins($application);

            return [
                'application' => $application,
                'user' => $user,
                'is_update' => false,
            ];
        });
    }

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    private function updateExisting(ChurchApplication $application, array $data, ?UploadedFile $frontId, ?UploadedFile $backId, ?UploadedFile $churchPermissionDoc = null): array
    {
        return DB::transaction(function () use ($application, $data, $frontId, $backId, $churchPermissionDoc) {
            /** @var array<string, mixed> $data */
            $oldValues = $application->toArray();

            /** @var string $churchName */
            $churchName = $data['church_name'];
            /** @var string $priestName */
            $priestName = $data['priest_name'];
            /** @var string $address */
            $address = $data['address'];

            $application->update([
                'church_name' => $churchName,
                'service_name' => $data['service_name'] ?? $application->service_name,
                'priest_name' => $priestName,
                'priest_phone' => $data['phone'] ?? $application->priest_phone,
                'main_servant_name' => $data['main_servant_name'] ?? $application->main_servant_name,
                'phone' => $data['phone'] ?? $application->phone,
                'address' => $address,
            ]);

            if ($application->status === 'rejected') {
                $application->update([
                    'status' => 'pending',
                    'rejection_reason' => null,
                    'rejected_at' => null,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                ]);

                User::where('church_application_id', $application->id)->update([
                    'application_status' => 'pending',
                ]);
            }

            $this->uploadApplicationFiles($application, $data, $frontId, $backId, $churchPermissionDoc);

            $this->auditService->log(
                action: 'church_application_updated',
                resourceType: 'church_application',
                resourceId: $application->id,
                oldValues: $oldValues,
                newValues: ['church_name' => $churchName, 'status' => $application->status],
            );

            $user = User::where('church_application_id', $application->id)->first();

            return [
                'application' => $application->fresh(),
                'user' => $user,
                'is_update' => true,
            ];
        });
    }

    /** @param array<string, mixed> $data */
    private function uploadApplicationFiles(ChurchApplication $application, array $data, ?UploadedFile $frontId, ?UploadedFile $backId, ?UploadedFile $churchPermissionDoc = null): void
    {
        /** @var string $idType */
        $idType = $data['id_type'] ?? 'national_id';
        $previousIdType = $application->church_permission_doc_path ? 'church_permission' : ($application->front_id_path ? 'national_id' : null);

        if ($idType === 'national_id') {
            if ($frontId) {
                if ($application->front_id_path) {
                    $this->fileUploadService->delete($application->front_id_path);
                }
                $path = $this->fileUploadService->uploadIdImage($frontId, (string) $application->id);
                $application->update(['front_id_path' => $path]);
            }

            if ($backId) {
                if ($application->back_id_path) {
                    $this->fileUploadService->delete($application->back_id_path);
                }
                $path = $this->fileUploadService->uploadIdImage($backId, (string) $application->id);
                $application->update(['back_id_path' => $path]);
            }

            if ($previousIdType === 'church_permission' && $application->church_permission_doc_path) {
                $this->fileUploadService->delete($application->church_permission_doc_path);
                $application->update(['church_permission_doc_path' => null]);
            }
        } elseif ($idType === 'church_permission' && $churchPermissionDoc) {
            if ($application->church_permission_doc_path) {
                $this->fileUploadService->delete($application->church_permission_doc_path);
            }
            $path = $this->fileUploadService->uploadDocumentFile($churchPermissionDoc, (string) $application->id);
            $application->update(['church_permission_doc_path' => $path]);

            if ($previousIdType === 'national_id') {
                if ($application->front_id_path) {
                    $this->fileUploadService->delete($application->front_id_path);
                    $application->update(['front_id_path' => null]);
                }
                if ($application->back_id_path) {
                    $this->fileUploadService->delete($application->back_id_path);
                    $application->update(['back_id_path' => null]);
                }
            }
        }
    }

    public function uploadIdImage(ChurchApplication $application, string $side, UploadedFile $image): ChurchApplication
    {
        $field = $side === 'front' ? 'front_id_path' : 'back_id_path';
        if ($application->$field) {
            $this->fileUploadService->delete($application->$field);
        }
        $path = $this->fileUploadService->uploadIdImage($image, (string) $application->id);
        $application->update([$field => $path]);
        return $application->fresh() ?? $application;
    }

    public function approve(ChurchApplication $application, User $platformAdmin, ?string $notes = null): Church
    {
        if ($application->status !== 'pending') {
            throw ValidationException::withMessages([
                'application' => [__('church_application.already_processed', ['status' => $application->status])],
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

            /** @var string|null $existingNotes */
            $existingNotes = $application->admin_notes;
            $application->update([
                'status' => 'approved',
                'reviewed_by' => $platformAdmin->id,
                'reviewed_at' => now(),
                'admin_notes' => $notes ? ($existingNotes ? $existingNotes . "\n" . $notes : $notes) : $existingNotes,
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
                    $admin->notify(new ApplicationApprovedNotification($admin, $church->name));
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
                'application' => [__('church_application.already_processed', ['status' => $application->status])],
            ]);
        }

        return DB::transaction(function () use ($application, $platformAdmin, $reason) {
            /** @var string $oldStatus */
            $oldStatus = $application->status;

            $application->update([
                'status' => 'rejected',
                'reviewed_by' => $platformAdmin->id,
                'reviewed_at' => now(),
                'rejected_at' => now(),
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

            return $application->fresh() ?? $application;
        });
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\ChurchApplication> */
    public function listApplications(?string $status = null, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = ChurchApplication::query();

        if ($status && in_array($status, ['pending', 'approved', 'rejected'])) {
            $query->where('status', $status);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    private function notifyPlatformAdmins(ChurchApplication $application): void
    {
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
    }
}
