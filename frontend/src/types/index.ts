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

export type EventType = 'service' | 'trip' | 'meeting' | 'other'

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
  location: string | null
  is_active: boolean
  is_all_classes: boolean
  target_classes: { id: number; name: string }[] | null
  classe: { id: number; name: string } | null
  class_id: number | null
  creator: { id: number; name: string } | null
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
  password?: string
  role: UserRole
  birthday?: string | null
  class_id?: number | null
  phone?: string | null
  address?: string | null
  member_address?: string | null
  is_active?: boolean
}

export interface CreateQRInvitePayload {
  type: QRInviteType
  class_id?: number | null
  attendance_context_id?: number | null
  max_uses?: number | null
  expires_in_hours?: number | null
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

export interface ForgotPasswordPayload {
  email: string
}

export interface ResetPasswordPayload {
  email: string
  token: string
  password: string
  password_confirmation: string
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
  status: 'pending' | 'approved' | 'rejected'
  status_label: string
  rejection_reason: string | null
  reviewer: { id: number; name: string } | null
  reviewed_at: string | null
  token_expires_at: string | null
  used_at: string | null
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
