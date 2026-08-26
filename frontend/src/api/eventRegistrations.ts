import type {
  Event,
  EventAccommodationDashboard,
  EventBusItem,
  EventDashboardStats,
  EventPayment,
  EventRegistration,
  EventRoom,
  EventSession,
  EventSpeaker,
  PaginationMeta,
} from '@/types'
import client from './client'

/*
 | Registrations
 */

export async function listRegistrations(
  eventId: number,
  params?: Record<string, string | number | boolean | undefined>,
): Promise<{ data: EventRegistration[]; meta: PaginationMeta }> {
  const { data } = await client.get(`/events/${eventId}/registrations`, { params })
  return data
}

export async function addParticipant(
  eventId: number,
  payload: { user_id: number; notes?: string },
): Promise<EventRegistration> {
  const { data } = await client.post<{ data: EventRegistration }>(`/events/${eventId}/registrations`, payload)
  return data.data
}

export async function registerSelf(eventId: number): Promise<EventRegistration> {
  const { data } = await client.post<{ data: EventRegistration }>(`/events/${eventId}/register-self`)
  return data.data
}

export async function myRegistrations(): Promise<EventRegistration[]> {
  const { data } = await client.get<{ data: EventRegistration[] }>('/events/my-registrations')
  return data.data
}

export async function confirmRegistration(eventId: number, regId: number): Promise<void> {
  await client.post(`/events/${eventId}/registrations/${regId}/confirm`)
}

export async function cancelRegistration(eventId: number, regId: number): Promise<void> {
  await client.post(`/events/${eventId}/registrations/${regId}/cancel`)
}

export async function waitlistRegistration(eventId: number, regId: number): Promise<void> {
  await client.post(`/events/${eventId}/registrations/${regId}/waitlist`)
}

export async function removeRegistration(eventId: number, regId: number): Promise<void> {
  await client.delete(`/events/${eventId}/registrations/${regId}`)
}

export async function updateRegistration(
  eventId: number,
  regId: number,
  payload: { notes?: string; attendance_status?: string },
): Promise<void> {
  await client.put(`/events/${eventId}/registrations/${regId}`, payload)
}

export async function checkInByToken(eventId: number, qrToken: string): Promise<EventRegistration> {
  const { data } = await client.post<{ data: EventRegistration }>(
    `/events/${eventId}/registrations/check-in-by-token`,
    { qr_token: qrToken },
  )
  return data.data
}

export async function checkIn(eventId: number, regId: number): Promise<void> {
  await client.post(`/events/${eventId}/registrations/${regId}/check-in`)
}

export async function undoCheckIn(eventId: number, regId: number): Promise<void> {
  await client.post(`/events/${eventId}/registrations/${regId}/undo-check-in`)
}

export async function assignBus(eventId: number, regId: number, busId: number | null): Promise<void> {
  await client.post(`/events/${eventId}/registrations/${regId}/assign-bus`, { bus_id: busId })
}

/*
 | Payments
 */

export async function listPayments(
  eventId: number,
): Promise<{ data: EventPayment[]; summary: import('@/types').EventFinancialSummary; meta: PaginationMeta }> {
  const { data } = await client.get(`/events/${eventId}/payments`)
  return data
}

export async function recordPayment(
  eventId: number,
  regId: number,
  payload: { amount: number; method: string; paid_at?: string; note?: string },
): Promise<EventPayment> {
  const { data } = await client.post<{ data: EventPayment }>(
    `/events/${eventId}/registrations/${regId}/payments`,
    payload,
  )
  return data.data
}

export async function refundPayment(eventId: number, regId: number, paymentId: number): Promise<void> {
  await client.post(`/events/${eventId}/registrations/${regId}/payments/${paymentId}/refund`)
}

/*
 | Buses (trips)
 */

export async function listBuses(eventId: number): Promise<EventBusItem[]> {
  const { data } = await client.get<{ data: EventBusItem[] }>(`/events/${eventId}/buses`)
  return data.data
}

export async function createBus(
  eventId: number,
  payload: { bus_number: string; capacity: number; driver_name?: string; coordinator_name?: string },
): Promise<EventBusItem> {
  const { data } = await client.post<{ data: EventBusItem }>(`/events/${eventId}/buses`, payload)
  return data.data
}

export async function updateBus(eventId: number, busId: number, payload: Record<string, unknown>): Promise<void> {
  await client.put(`/events/${eventId}/buses/${busId}`, payload)
}

export async function deleteBus(eventId: number, busId: number): Promise<void> {
  await client.delete(`/events/${eventId}/buses/${busId}`)
}

/*
 | Sessions + Speakers (conferences)
 */

export async function listSessions(eventId: number): Promise<EventSession[]> {
  const { data } = await client.get<{ data: EventSession[] }>(`/events/${eventId}/sessions`)
  return data.data
}

export async function createSession(eventId: number, payload: Record<string, unknown>): Promise<EventSession> {
  const { data } = await client.post<{ data: EventSession }>(`/events/${eventId}/sessions`, payload)
  return data.data
}

export async function updateSession(eventId: number, sessionId: number, payload: Record<string, unknown>): Promise<void> {
  await client.put(`/events/${eventId}/sessions/${sessionId}`, payload)
}

export async function deleteSession(eventId: number, sessionId: number): Promise<void> {
  await client.delete(`/events/${eventId}/sessions/${sessionId}`)
}

export async function listSpeakers(eventId: number): Promise<EventSpeaker[]> {
  const { data } = await client.get<{ data: EventSpeaker[] }>(`/events/${eventId}/speakers`)
  return data.data
}

export async function createSpeaker(eventId: number, payload: Record<string, unknown>): Promise<EventSpeaker> {
  const { data } = await client.post<{ data: EventSpeaker }>(`/events/${eventId}/speakers`, payload)
  return data.data
}

export async function deleteSpeaker(eventId: number, speakerId: number): Promise<void> {
  await client.delete(`/events/${eventId}/speakers/${speakerId}`)
}

/*
 | Dashboard + lifecycle + reports
 */

export async function getEventDashboard(eventId: number): Promise<EventDashboardStats> {
  const { data } = await client.get<{ data: EventDashboardStats }>(`/events/${eventId}/dashboard`)
  return data.data
}

export async function publishEvent(id: number): Promise<Event> {
  const { data } = await client.post<{ data: Event }>(`/events/${id}/publish`)
  return data.data
}

export async function closeRegistration(id: number): Promise<Event> {
  const { data } = await client.post<{ data: Event }>(`/events/${id}/close-registration`)
  return data.data
}

export async function reopenRegistration(id: number): Promise<Event> {
  const { data } = await client.post<{ data: Event }>(`/events/${id}/reopen-registration`)
  return data.data
}

export async function cancelEvent(id: number): Promise<Event> {
  const { data } = await client.post<{ data: Event }>(`/events/${id}/cancel`)
  return data.data
}

export async function completeEvent(id: number): Promise<Event> {
  const { data } = await client.post<{ data: Event }>(`/events/${id}/complete`)
  return data.data
}

export async function duplicateEvent(id: number): Promise<Event> {
  const { data } = await client.post<{ data: Event }>(`/events/${id}/duplicate`)
  return data.data
}

export function reportUrl(eventId: number, type: 'participants' | 'financial' | 'attendance'): string {
  const base = client.defaults.baseURL ?? '/api/v1'
  return `${base}/events/${eventId}/reports/${type}`
}

/*
 | My Assigned Events — servant
 */

export async function getMyAssignedEvents(
  params?: Record<string, string | number | boolean | undefined>,
): Promise<{ data: Event[]; meta: PaginationMeta }> {
  const { data } = await client.get('/events/my-assigned', { params })
  return data
}

/*
 | Reservations — approve / reject
 */

export async function approveReservation(eventId: number, regId: number): Promise<EventRegistration> {
  const { data } = await client.post<{ data: EventRegistration }>(`/events/${eventId}/registrations/${regId}/approve`)
  return data.data
}

export async function rejectReservation(eventId: number, regId: number, reason?: string): Promise<EventRegistration> {
  const { data } = await client.post<{ data: EventRegistration }>(`/events/${eventId}/registrations/${regId}/reject`, { reason })
  return data.data
}

/*
 | Member Reservation Requests — member submits their reservation status for Conference/Trip events
 */

export async function submitMemberReservationRequest(
  eventId: number,
  status: 'booked' | 'not_reserved' | 'thinking',
  details?: {
    booked_with?: string
    amount_paid?: string
    medical_notes?: string
    medication_name?: string
    medication_time?: string
  },
): Promise<EventRegistration> {
  const { data } = await client.post<{ data: EventRegistration }>(
    `/events/${eventId}/member-reservation-request`,
    {
      status,
      ...details,
    },
  )
  return data.data
}

/*
 | Reservation Requests — for servant CRM view
 */

export async function listEventReservationRequests(eventId: number): Promise<EventRegistration[]> {
  const { data } = await client.get<{ data: EventRegistration[] }>(`/events/${eventId}/reservation-requests`)
  return data.data
}

/*
 | Accommodation — rooms, cells, assignments
 */

export async function getAccommodationDashboard(eventId: number): Promise<EventAccommodationDashboard> {
  const { data } = await client.get<{ data: EventAccommodationDashboard }>(`/events/${eventId}/accommodation/dashboard`)
  return data.data
}

export async function listRooms(
  eventId: number,
  params?: Record<string, string | number | boolean>,
): Promise<{ data: EventRoom[]; meta: PaginationMeta }> {
  const { data } = await client.get(`/events/${eventId}/accommodation/rooms`, { params })
  return data
}

export async function getRoom(eventId: number, roomId: number): Promise<EventRoom> {
  const { data } = await client.get<{ data: EventRoom }>(`/events/${eventId}/accommodation/rooms/${roomId}`)
  return data.data
}

export async function createRooms(
  eventId: number,
  roomGroups: { count: number; capacity: number }[],
): Promise<{ rooms_created: number; cells_created: number; total_capacity: number; member_capacity: number }> {
  const { data } = await client.post<{ data: { rooms_created: number; cells_created: number; total_capacity: number; member_capacity: number } }>(
    `/events/${eventId}/accommodation/rooms`,
    { room_groups: roomGroups },
  )
  return data.data
}

export async function updateRoom(
  eventId: number,
  roomId: number,
  payload: { capacity?: number; is_active?: boolean },
): Promise<EventRoom> {
  const { data } = await client.put<{ data: EventRoom }>(`/events/${eventId}/accommodation/rooms/${roomId}`, payload)
  return data.data
}

export async function deleteRoom(eventId: number, roomId: number): Promise<void> {
  await client.delete(`/events/${eventId}/accommodation/rooms/${roomId}`)
}

export async function assignAccommodation(
  eventId: number,
  registrationId: number,
  cellId: number,
): Promise<void> {
  await client.post(`/events/${eventId}/accommodation/assign`, {
    registration_id: registrationId,
    cell_id: cellId,
  })
}

export async function removeAccommodation(eventId: number, registrationId: number): Promise<void> {
  await client.delete(`/events/${eventId}/accommodation/registrations/${registrationId}`)
}

export async function listUnaccommodated(
  eventId: number,
  params?: Record<string, string | number | boolean>,
): Promise<{ data: EventRegistration[]; meta: PaginationMeta }> {
  const { data } = await client.get(`/events/${eventId}/accommodation/unaccommodated`, { params })
  return data
}

/*
 | Member accommodation — approval-gated view + self-selection
 */

export interface MemberCell {
  id: number
  cell_number: number
  type: 'servant_reserved' | 'member'
  is_available: boolean
}

export interface MemberRoomView {
  id: number
  room_number: number
  capacity: number
  cells: MemberCell[]
}

export interface MemberAccommodationView {
  registration_status: string | null
  accommodation: { cell_id: number; room_number: number | null; cell_number: number | null } | null
  rooms: MemberRoomView[]
}

export async function myAccommodationView(eventId: number): Promise<MemberAccommodationView> {
  const { data } = await client.get<{ data: MemberAccommodationView }>(`/events/${eventId}/accommodation/my-view`)
  return data.data
}

export async function selectMyCell(
  eventId: number,
  cellId: number,
): Promise<{ cell_id: number; room_number: number | null; cell_number: number | null }> {
  const { data } = await client.post<{ message: string; data: { cell_id: number; room_number: number | null; cell_number: number | null } }>(
    `/events/${eventId}/accommodation/select-cell`,
    { cell_id: cellId },
  )
  return data.data
}
