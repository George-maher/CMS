<?php

namespace App\Contracts;

use App\Models\Church;
use App\Models\ChurchApplication;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;

interface ChurchApplicationServiceInterface
{
    public function findByEmail(string $email): ?ChurchApplication;

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function submit(array $data, ?UploadedFile $frontId, ?UploadedFile $backId, string $email, string $password, ?UploadedFile $churchPermissionDoc = null): array;

    public function approve(ChurchApplication $application, User $platformAdmin, ?string $notes = null): Church;

    public function reject(ChurchApplication $application, User $platformAdmin, string $reason): ChurchApplication;

    /** @return LengthAwarePaginator<int, ChurchApplication> */
    public function listApplications(?string $status = null, int $perPage = 15);

    public function uploadIdImage(ChurchApplication $application, string $side, UploadedFile $image): ChurchApplication;
}
