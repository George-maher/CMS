<?php

namespace App\Observers;

use App\Contracts\StorageServiceInterface;
use App\Models\ChurchApplication;
use Illuminate\Support\Facades\App;

class ChurchApplicationObserver
{
    public function deleted(ChurchApplication $application): void
    {
        $this->cleanupFiles($application);
    }

    public function forceDeleted(ChurchApplication $application): void
    {
        $this->cleanupFiles($application);
    }

    private function cleanupFiles(ChurchApplication $application): void
    {
        $storage = App::make(StorageServiceInterface::class);

        $files = array_filter([
            $application->front_id_path,
            $application->back_id_path,
            $application->church_permission_doc_path,
        ]);

        foreach ($files as $url) {
            $storage->deleteFile($url);
        }
    }
}
