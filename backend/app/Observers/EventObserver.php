<?php

namespace App\Observers;

use App\Contracts\StorageServiceInterface;
use App\Models\Event;
use Illuminate\Support\Facades\App;

class EventObserver
{
    public function deleted(Event $event): void
    {
        $this->cleanupFiles($event);
    }

    public function forceDeleted(Event $event): void
    {
        $this->cleanupFiles($event);
    }

    private function cleanupFiles(Event $event): void
    {
        if (!$event->image) {
            return;
        }

        $storage = App::make(StorageServiceInterface::class);
        $storage->deleteFile($event->image);
    }
}
