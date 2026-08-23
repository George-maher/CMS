<?php

namespace App\Contracts;

use App\Models\Event;
use Symfony\Component\HttpFoundation\Response;

interface EventReportServiceInterface
{
    /** @return array<string, mixed> */
    public function dashboard(Event $event): array;

    public function participantsReportCsv(Event $event): Response;

    public function financialReportCsv(Event $event): Response;

    public function attendanceReportCsv(Event $event): Response;
}
