<?php

namespace App\Observers;

use App\Contracts\StorageServiceInterface;
use App\Models\User;
use Illuminate\Support\Facades\App;

class UserObserver
{
    public function deleted(User $user): void
    {
        if ($user->isForceDeleting()) {
            $this->cleanupFiles($user);
        }
    }

    public function forceDeleted(User $user): void
    {
        $this->cleanupFiles($user);
    }

    private function cleanupFiles(User $user): void
    {
        $storage = App::make(StorageServiceInterface::class);

        $files = array_filter([
            $user->avatar,
        ]);

        foreach ($files as $url) {
            $storage->deleteFile($url);
        }
    }
}
