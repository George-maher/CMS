<?php

namespace App\Services;

use App\Contracts\EventPaymentServiceInterface;
use App\Contracts\EventReportServiceInterface;
use App\Enums\EventAttendanceStatus;
use App\Enums\RegistrationStatus;
use App\Models\Event;
use App\Models\EventAccommodation;
use App\Models\EventRegistration;
use App\Models\EventRoom;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EventReportService implements EventReportServiceInterface
{
    /** @var array<int, string> */
    private const ACTIVE_STATUSES = [
        RegistrationStatus::Pending->value,
        RegistrationStatus::Confirmed->value,
    ];

    public function __construct(
        private readonly EventPaymentServiceInterface $paymentService,
    ) {}

    /** @return array<string, mixed> */
    public function dashboard(Event $event): array
    {
        $registered = $this->countRegistrations($event, self::ACTIVE_STATUSES);
        $waitlisted = $this->countRegistrations($event, [RegistrationStatus::Waitlisted->value]);
        $checkedIn = $this->countCheckedIn($event);

        $capacity = $event->max_capacity;
        $available = $capacity !== null ? max(0, $capacity - $registered) : null;
        $occupancy = ($capacity !== null && $capacity > 0) ? round(($registered / $capacity) * 100) : 0;

        $attendancePercentage = $registered > 0 ? round(($checkedIn / $registered) * 100) : 0;
        $absent = max(0, $registered - $checkedIn);

        return [
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'type' => $event->type?->value,
                'status' => $event->status?->value,
                'location' => $event->location,
                'event_date' => $event->event_date?->toISOString(),
                'end_date' => $event->end_date?->toDateString(),
                'start_time' => $event->start_time,
                'end_time' => $event->end_time,
            ],
            'statistics' => [
                'max_capacity' => $capacity,
                'total_registered' => $registered,
                'available_spaces' => $available,
                'waitlisted' => $waitlisted,
                'occupancy_percentage' => $occupancy,
                'is_full' => $capacity !== null && $available !== null && $available === 0,
            ],
            'payments' => $this->paymentService->financialSummary($event),
            'attendance' => [
                'total_registered' => $registered,
                'checked_in' => $checkedIn,
                'absent' => $absent,
                'attendance_percentage' => $attendancePercentage,
            ],
            'accommodation' => $this->accommodationStats($event),
        ];
    }

    /** @return array<string, mixed> */
    private function accommodationStats(Event $event): array
    {
        $hasAccommodation = $event->rooms()->exists();

        if (! $hasAccommodation) {
            return [
                'enabled' => false,
            ];
        }

        $totalRooms = EventRoom::query()->where('event_id', $event->id)->count();
        $totalCapacity = (int) EventRoom::query()->where('event_id', $event->id)->sum('capacity');
        $totalMemberCapacity = (int) EventRoom::query()->where('event_id', $event->id)->sum('member_capacity');
        $accommodated = EventAccommodation::query()
            ->whereHas('registration', fn ($q) => $q->where('event_id', $event->id))
            ->count();
        $approvedCount = EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('status', RegistrationStatus::Approved->value)
            ->count();

        return [
            'enabled' => true,
            'total_rooms' => $totalRooms,
            'total_capacity' => $totalCapacity,
            'total_member_capacity' => $totalMemberCapacity,
            'approved_reservations' => $approvedCount,
            'accommodated' => $accommodated,
            'not_accommodated' => max(0, $approvedCount - $accommodated),
        ];
    }

    public function participantsReportCsv(Event $event): Response
    {
        $rows = EventRegistration::query()
            ->where('event_id', $event->id)
            ->with(['user.classe', 'bus', 'accommodation.cell.room'])
            ->orderBy('created_at')
            ->get();

        return $this->streamCsv('participants-'.$event->id.'.csv', [
            ['Member Name', 'Phone', 'Class', 'Registration Status', 'Payment Status', 'Amount Paid', 'Attendance', 'Bus', 'Accommodation', 'Registered At', 'Notes'],
            ...$rows->map(fn (EventRegistration $r): array => [
                strval($r->user->name ?? ''),
                strval($r->user->phone ?? ''),
                strval($r->user->classe->name ?? ''),
                $r->status->label(),
                $r->payment_status->label(),
                number_format((float) $r->amount_paid, 2),
                $r->attendance_status->label(),
                strval($r->bus->bus_number ?? ''),
                $r->accommodation ? 'Room '.$r->accommodation->cell->room->room_number.' / Cell '.$r->accommodation->cell->cell_number : '',
                strval($r->created_at !== null ? $r->created_at->format('Y-m-d H:i') : ''),
                strval($r->notes ?? ''),
            ])->all(),
        ]);
    }

    public function financialReportCsv(Event $event): Response
    {
        $summary = $this->paymentService->financialSummary($event);

        return $this->streamCsv('financial-'.$event->id.'.csv', [
            ['Event', $event->name],
            ['Price per Participant', number_format(self::num($summary['price_per_participant']), 2)],
            ['Active Registrations', strval(self::num($summary['active_registrations']))],
            ['Expected Revenue', number_format(self::num($summary['expected_revenue']), 2)],
            ['Collected', number_format(self::num($summary['collected']), 2)],
            ['Refunded', number_format(self::num($summary['refunded']), 2)],
            ['Remaining', number_format(self::num($summary['remaining']), 2)],
            ['Paid Participants', strval(self::num($summary['paid_participants']))],
            ['Unpaid Participants', strval(self::num($summary['unpaid_participants']))],
        ]);
    }

    public function attendanceReportCsv(Event $event): Response
    {
        $dashboard = $this->dashboard($event);
        /** @var array<string, mixed> $attendance */
        $attendance = $dashboard['attendance'];

        return $this->streamCsv('attendance-'.$event->id.'.csv', [
            ['Event', $event->name],
            ['Registered', strval(self::num($attendance['total_registered']))],
            ['Checked In', strval(self::num($attendance['checked_in']))],
            ['Absent', strval(self::num($attendance['absent']))],
            ['Attendance Percentage', self::num($attendance['attendance_percentage']).'%'],
        ]);
    }

    /**
     * @param  array<int, string>  $statuses
     */
    private function countRegistrations(Event $event, array $statuses): int
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->whereIn('status', $statuses)
            ->count();
    }

    private function countCheckedIn(Event $event): int
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where('attendance_status', EventAttendanceStatus::CheckedIn->value)
            ->count();
    }

    private static function num(mixed $value): float|int
    {
        return is_numeric($value) ? ($value + 0) : 0;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function streamCsv(string $filename, array $rows): Response
    {
        $response = new StreamedResponse(function () use ($rows): void {
            $handle = fopen('php://output', 'rb+');

            if ($handle === false) {
                return;
            }

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');

        return $response;
    }
}
