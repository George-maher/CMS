<?php

namespace App\Observers;

use App\Contracts\StorageServiceInterface;
use App\Models\MembershipRequest;
use Illuminate\Support\Facades\App;

class MembershipRequestObserver
{
    public function deleted(MembershipRequest $request): void
    {
        $this->cleanupFiles($request);
    }

    public function forceDeleted(MembershipRequest $request): void
    {
        $this->cleanupFiles($request);
    }

    private function cleanupFiles(MembershipRequest $request): void
    {
        if (!$request->file_url) {
            return;
        }

        $storage = App::make(StorageServiceInterface::class);
        $storage->deleteFile($request->file_url);
    }
}
