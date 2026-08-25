import type { EventStatus, RegistrationStatus, EventPaymentStatus, EventAttendanceStatus } from '@/types'

type BadgeVariant = 'default' | 'success' | 'warning' | 'danger' | 'info' | 'primary' | 'gold'

export const eventStatusVariant: Record<EventStatus, BadgeVariant> = {
  draft: 'default',
  open: 'success',
  closed: 'warning',
  completed: 'info',
  cancelled: 'danger',
}

export function eventStatusLabelKey(status: string): string {
  return `eventMgmt.status_${status}`
}

export const registrationStatusVariant: Record<RegistrationStatus, BadgeVariant> = {
  pending: 'warning',
  confirmed: 'success',
  cancelled: 'danger',
  waitlisted: 'info',
  approved: 'success',
  rejected: 'danger',
  booked: 'success',
  not_reserved: 'warning',
  thinking: 'info',
}

export function registrationStatusLabelKey(status: string): string {
  return `eventMgmt.reg_${status}`
}

export const paymentStatusVariant: Record<EventPaymentStatus, BadgeVariant> = {
  unpaid: 'danger',
  partially_paid: 'warning',
  paid: 'success',
  refunded: 'info',
}

export function paymentStatusLabelKey(status: string): string {
  return `eventMgmt.pay_${status}`
}

export const attendanceStatusVariant: Record<EventAttendanceStatus, BadgeVariant> = {
  not_checked_in: 'default',
  checked_in: 'success',
  absent: 'danger',
}

export function attendanceStatusLabelKey(status: string): string {
  return `eventMgmt.att_${status}`
}
