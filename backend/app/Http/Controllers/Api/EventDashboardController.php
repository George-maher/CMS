<?php

namespace App\Http\Controllers\Api;

use App\Contracts\EventReportServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class EventDashboardController extends Controller
{
    public function __construct(
        private readonly EventReportServiceInterface $reportService,
    ) {}

    public function dashboard(int $id): JsonResponse
    {
        $event = Event::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        return response()->json([
            'data' => $this->reportService->dashboard($event),
        ]);
    }

    public function participantsReport(int $id): Response
    {
        return $this->reportForEvent($id, 'participants');
    }

    public function financialReport(int $id): Response
    {
        return $this->reportForEvent($id, 'financial');
    }

    public function attendanceReport(int $id): Response
    {
        return $this->reportForEvent($id, 'attendance');
    }

    private function reportForEvent(int $id, string $type): Response
    {
        $event = Event::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        return match ($type) {
            'financial' => $this->reportService->financialReportCsv($event),
            'attendance' => $this->reportService->attendanceReportCsv($event),
            default => $this->reportService->participantsReportCsv($event),
        };
    }
}
