export interface Stage {
  id: number
  name: string
  display_order: number
  classes_count: number
  created_at: string
}

export interface Classe {
  id: number
  stage_id: number
  name: string
  description: string | null
  display_order: number
  member_count: number
  servant_count: number
  stage: { id: number; name: string } | null
  created_at: string
}

export interface StageWithClasses extends Stage {
  classes: Classe[]
}

export type UserRole = 'platform_admin' | 'admin' | 'assistant_admin' | 'servant' | 'member'

export type QRInviteType =
  | 'admin_to_servant_invite'
  | 'servant_to_member_invite'
  | 'attendance_qr'

export type PointType = 'attendance' | 'bonus' | 'adjustment'

export interface User {
  id: number
  member_id?: string | null
  church_id: number | null
  church: { id: number; name: string; slug: string } | null
  name: string
  email: string
  birthday: string | null
  age: number | null
  role: UserRole
  role_label: string
  class_id: number | null
  classe: { id: number; name: string; stage?: { id: number; name: string } } | null
  stage: { id: number; name: string } | null
  phone: string | null
  address: string | null
  member_address: string | null
  avatar: string | null
  is_active: boolean
  application_status: 'pending' | 'approved' | 'rejected'
  email_verified_at: string | null
  attendance_qr_token: string | null
  total_points: number
  assigned_members_count?: number
  servant?: { id: number; name: string; phone: string | null } | null
  created_by: { id: number; name: string } | null
  created_at: string
  updated_at: string
}

export type InviteStatus = 'unused' | 'partial' | 'used' | 'expired' | 'revoked'

export interface QRInvite {
  id: number
  type: QRInviteType
  type_label: string
  status: InviteStatus
  creator: { id: number; name: string } | null
  used_by: {
    id: number
    name: string
    role?: string
    phone?: string
    member_id?: string
    class_id?: number | null
    class_name?: string
    stage_name?: string
    created_at?: string
  } | null
  used_by_users: {
    id: number
    name: string
    role?: string
    phone?: string
    member_id?: string
    class_id?: number | null
    class_name?: string
    stage_name?: string
    used_at?: string
  }[] | null
  classe: { id: number; name: string; stage_id?: number; stage_name?: string } | null
  attendance_context: { id: number; name: string; name_ar?: string | null; slug: string } | null
  expires_at: string
  used_at: string | null
  is_revoked: boolean
  is_valid: boolean
  is_expired: boolean
  is_used: boolean
  is_single_use: boolean
  use_count: number
  max_uses: number | null
  remaining_uses: number | null
  usage_label: string | null
  created_at: string
}

export type EventType = 'service' | 'conference' | 'trip' | 'meeting' | 'other'

export type EventStatus = 'draft' | 'open' | 'closed' | 'completed' | 'cancelled'
export type RegistrationStatus = 'pending' | 'confirmed' | 'cancelled' | 'waitlisted' | 'approved' | 'rejected'
export type EventPaymentStatus = 'unpaid' | 'partially_paid' | 'paid' | 'refunded'
export type EventAttendanceStatus = 'not_checked_in' | 'checked_in' | 'absent'

export interface EventSession {
  id: number
  event_id: number
  title: string
  description: string | null
  speaker_name: string | null
  starts_at: string | null
  ends_at: string | null
  display_order: number
}

export interface EventSpeaker {
  id: number
  event_id: number
  name: string
  title: string | null
  bio: string | null
}

export interface EventBusItem {
  id: number
  event_id: number
  bus_number: string
  capacity: number
  driver_name: string | null
  coordinator_name: string | null
  assigned_count: number
  available_seats: number
  occupancy_percentage: number
  created_at: string
}

export interface EventRegistration {
  id: number
  event_id: number
  user: { id: number; name: string; phone: string | null; avatar: string | null; class_name: string | null } | null
  registrar?: { id: number; name: string } | null
  bus?: { id: number; bus_number: string } | null
  bus_id: number | null
  status: RegistrationStatus
  status_label: string
  payment_status: EventPaymentStatus
  payment_status_label: string
  amount_paid: string
  attendance_status: EventAttendanceStatus
  attendance_status_label: string
  checked_in_at: string | null
  qr_token?: string
  notes: string | null
  booking_with: string | null
  medical_notes: string | null
  rejection_reason: string | null
  accommodation?: {
    id: number
    cell: {
      id: number
      cell_number: number
      type: string
      room: { id: number; room_number: number }
    }
  } | null
  registered_at: string
}

export interface EventPayment {
  id: number
  registration_id: number
  member?: { id: number; name: string } | null
  amount: string
  method: 'cash' | 'bank_transfer' | 'other'
  method_label: string
  paid_at: string
  note: string | null
  refunded: boolean
  recorded_by_name?: string
}

export interface EventFinancialSummary {
  price_per_participant: number
  active_registrations: number
  expected_revenue: number
  collected: number
  refunded: number
  remaining: number
  paid_participants: number
  unpaid_participants: number
  net_result: number
}

export interface EventDashboardStats {
  event: {
    id: number
    name: string
    type: EventType | null
    status: EventStatus | null
    location: string | null
    event_date: string | null
    end_date: string | null
    start_time: string | null
    end_time: string | null
  }
  statistics: {
    max_capacity: number | null
    total_registered: number
    available_spaces: number | null
    waitlisted: number
    occupancy_percentage: number
    is_full: boolean
  }
  payments: EventFinancialSummary
  attendance: {
    total_registered: number
    checked_in: number
    absent: number
    attendance_percentage: number
  }
  accommodation?: {
    enabled: boolean
    total_rooms?: number
    total_capacity?: number
    total_member_capacity?: number
    approved_reservations?: number
    accommodated?: number
    not_accommodated?: number
  }
}

export interface EventViewEntry {
  user: { id: number; name: string }
  viewed_at: string
}

export interface EventViewer {
  id: number
  name: string
  email: string
  member_id: string | null
  classe: { id: number; name: string } | null
  viewed_at?: string
}

export interface Event {
  id: number
  name: string
  type: EventType
  type_label: string
  image: string | null
  description: string | null
  preview: string | null
  event_date: string
  end_date?: string | null
  start_time?: string | null
  end_time?: string | null
  status?: EventStatus
  status_label?: string
  max_capacity?: number | null
  registered_count?: number
  available_spaces?: number | null
  occupancy_percentage?: number
  theme?: string | null
  target_age_group?: string | null
  destination?: string | null
  departure_location?: string | null
  departure_at?: string | null
  return_at?: string | null
  transportation_type?: string | null
  coordinator_name?: string | null
  coordinator_phone?: string | null
  price_per_participant?: string | null
  location: string | null
  is_active: boolean
  is_all_classes: boolean
  target_classes: { id: number; name: string }[] | null
  classe: { id: number; name: string } | null
  class_id: number | null
  creator: { id: number; name: string } | null
  responsible_servant_id?: number | null
  responsible_servant?: { id: number; name: string; phone: string | null; avatar: string | null } | null
  has_accommodation?: boolean
  rooms_count?: number
  total_capacity?: number
  total_member_capacity?: number
  pending_count?: number
  confirmed_count?: number
  approved_count?: number
  view_count?: number
  views?: EventViewEntry[]
  created_at: string
  updated_at: string
}

export interface AttendanceContext {
  id: number
  name: string
  name_ar: string | null
  slug: string
  description: string | null
  is_active: boolean
  created_by: number | null
  creator_name: string | null
  updated_by: number | null
  updater_name: string | null
  created_at: string
  updated_at: string
}

export interface PointAddedBy {
  id: number
  name: string
}

export interface Attendance {
  id: number
  user: User
  recorder: { id: number; name: string } | null
  classe: { id: number; name: string } | null
  event: { id: number; name: string } | null
  attendance_context: { id: number; name: string; name_ar?: string | null; slug: string } | null
  attendance_context_id: number | null
  method: string | null
  attended_at: string
  points_earned: number
  created_at: string
}

export interface Point {
  id: number
  user: { id: number; name: string } | null
  added_by: PointAddedBy | null
  points: number
  type: PointType
  type_label: string
  description: string | null
  created_at: string
}

export interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface ApiResponse<T> {
  data: T
  message?: string
  meta?: PaginationMeta
}

export interface LoginPayload {
  email: string
  password: string
}

export interface RegisterPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
  invite_token: string
  birthday?: string
  class_id?: number | null
  phone?: string
  address?: string
  member_address?: string
}

export interface CreateUserPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
  role: UserRole
  birthday?: string | null
  class_id?: number | null
  phone?: string | null
  address?: string | null
  member_id?: string | null
  member_address?: string | null
  is_active?: boolean
}

export interface CreateQRInvitePayload {
  type: QRInviteType
  class_id?: number | null
  attendance_context_id?: number | null
  max_uses?: number | null
  expires_in_hours?: number | null
  /** Idempotency key — prevents duplicate invites when the request is retried or double-submitted. */
  client_request_id?: string
}

export interface AddBonusPointsPayload {
  user_id: number
  points: number
  reason?: string
}

export type FeedbackCategory = 'complaint' | 'suggestion' | 'other'

export interface FeedbackReply {
  id: number
  message: string
  user: { id: number; name: string }
  created_at: string
}

export interface Feedback {
  id: number
  message: string
  category: FeedbackCategory | null
  category_label: string | null
  is_resolved: boolean
  has_new_reply: boolean
  is_anonymous: boolean
  user?: { id: number; name: string } | null
  sender: {
    id: number | null
    name: string
    phone: string | null
    class_id: number | null
    class_name: string | null
    stage_name: string | null
  }
  is_anonymous_to_servants?: boolean
  replies?: FeedbackReply[]
  created_at: string
  updated_at: string
}

export interface DailyVerse {
  id: number
  verse_text: string
  reference: string
  created_by: number
  creator_name: string
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface Church {
  id: number
  name: string
  slug: string
  priest_name: string | null
  main_servant_name: string | null
  priest_phone: string | null
  phone: string | null
  address: string | null
  contact_email: string | null
  is_active: boolean
  is_suspended: boolean
  member_count?: number
  created_at: string
}

export type ApplicationStatus = 'pending' | 'approved' | 'rejected'

export interface ChurchApplication {
  id: number
  church_name: string
  service_name: string | null
  priest_name: string
  main_servant_name: string | null
  priest_phone: string
  phone: string | null
  address: string | null
  contact_email: string | null
  front_id_url: string | null
  back_id_url: string | null
  church_permission_doc_url: string | null
  id_type: 'national_id' | 'church_permission' | null
  status: ApplicationStatus
  admin_notes: string | null
  rejection_reason: string | null
  rejected_at: string | null
  reviewed_by: { id: number; name: string } | null
  reviewed_at: string | null
  created_at: string
  updated_at: string
}

export interface PlatformDashboardStats {
  pending_applications: number
  approved_applications: number
  rejected_applications: number
  total_churches: number
  active_churches: number
  suspended_churches: number
  total_users: number
  recent_applications: { id: number; church_name: string; priest_name: string; created_at: string }[]
}

export interface ApplicationCounts {
  pending: number
  approved: number
  rejected: number
  total: number
}

export interface NotificationItem {
  id: number
  event_id: number | null
  feedback_id: number | null
  points_id: number | null
  title: string
  body: string | null
  type: string
  is_read: boolean
  read_at: string | null
  created_at: string
  event: { id: number; name: string; preview: string | null } | null
  feedback: {
    id: number
    message: string
    created_at: string
    replies: { id: number; message: string; user: { id: number; name: string }; created_at: string }[]
  } | null
  point: { id: number; points: number; description: string | null; created_at: string } | null
}

export interface MembershipRequest {
  id: number
  church_id: number
  name: string
  email: string
  phone: string | null
  birthday: string | null
  address: string | null
  preferred_role: string
  preferred_role_label: string
  status: 'pending' | 'approved' | 'rejected'
  notes: string | null
  rejection_reason: string | null
  file_url: string | null
  reviewer: { id: number; name: string } | null
  reviewed_at: string | null
  created_at: string
  updated_at: string
}

export interface PasswordResetRequest {
  id: number
  user_id: number
  email: string
  notes: string | null
  status: 'pending' | 'approved' | 'rejected' | 'completed'
  status_label: string
  rejection_reason: string | null
  reviewer: { id: number; name: string } | null
  reviewed_at: string | null
  created_at: string
  updated_at: string
  user: {
    id: number
    member_id: string | null
    name: string
    email: string
    role: string
    role_label: string
    phone: string | null
    avatar: string | null
    class_id: number | null
    classe: {
      id: number
      name: string
      stage: { id: number; name: string } | null
    } | null
  } | null
}

export interface LeaderboardEntry {
  rank: number
  user_id: number
  name: string
  avatar?: string | null
  email?: string
  total_points: number
  attendance_count?: number
  class_name?: string | null
  stage_name?: string | null
}

export interface ChurchDeletionSummary {
  church_id: number
  church_name: string
  total_users: number
  total_members: number
  total_servants: number
  total_admins: number
  total_events: number
  total_attendances: number
  total_attendance_contexts: number
  total_qr_invites: number
  total_points: number
  total_feedback: number
  total_feedback_replies: number
  total_event_views: number
  total_event_targets: number
  total_notifications: number
  total_daily_verses: number
  total_membership_requests: number
  total_stages: number
  total_classes: number
  total_password_reset_requests: number
  total_audit_logs: number
  total_records: number
  deleted_at?: string
  deleted_by?: string
  deletion_type?: 'soft' | 'hard' | null
  recoverable_until?: string
  is_recoverable?: boolean
  days_until_purge?: number | null
  already_deleted?: boolean
}

export interface StageLeaderboard {
  stage_id: number
  stage_name: string
  classes: {
    id: number
    name: string
    leaderboard: LeaderboardEntry[]
  }[]
}

export interface EventRoom {
  id: number
  event_id: number
  room_number: number
  capacity: number
  member_capacity: number
  is_active: boolean
  total_cells?: number
  occupied_cells?: number
  available_cells?: number
  cells?: EventRoomCell[]
  created_at: string
}

export interface EventRoomCell {
  id: number
  room_id: number
  cell_number: number
  type: 'servant_reserved' | 'member'
  type_label: string
  is_available: boolean
  accommodation?: {
    id: number
    registration_id: number
    user: { id: number; name: string }
  } | null
}

export interface EventAccommodationDashboard {
  total_rooms: number
  total_capacity: number
  servant_capacity: number
  member_capacity: number
  approved_reservations: number
  accommodated: number
  not_accommodated: number
  occupied_member_cells: number
  available_member_cells: number
}
