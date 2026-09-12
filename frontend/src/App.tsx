import { BrowserRouter, Routes, Route, useLocation } from 'react-router-dom'
import { Suspense, lazy, useEffect, useRef, type ReactNode } from 'react'
import { Toaster } from 'react-hot-toast'
import { AuthProvider } from '@/contexts/AuthContext'
import { ThemeProvider } from '@/contexts/ThemeContext'
import AppLayout from '@/components/layout/AppLayout'
import OfflineBanner from '@/components/common/OfflineBanner'
import { useOffline } from '@/contexts/OfflineContext'
import { useSync } from '@/contexts/SyncContext'
import { useAuth } from '@/hooks/useAuth'
import { prefetchRoutesWhenIdle } from '@/lib/routePrefetch'
import { preheatData } from '@/lib/dataPrefetch'
import { recordRouteTransition } from '@/lib/perf'

const FullPageSpinner = (
  <div className="flex min-h-screen items-center justify-center">
    <div className="h-8 w-8 animate-spin rounded-full border-2 border-primary-400 border-t-transparent" />
  </div>
)

const Landing = lazy(() => import('@/pages/Landing'))
const JoinNow = lazy(() => import('@/pages/JoinNow'))
const Login = lazy(() => import('@/pages/auth/Login'))
const PlatformLogin = lazy(() => import('@/pages/auth/PlatformLogin'))
const ForgotPassword = lazy(() => import('@/pages/auth/ForgotPassword'))
const VerifyEmail = lazy(() => import('@/pages/auth/VerifyEmail'))
const InviteRegister = lazy(() => import('@/pages/auth/InviteRegister'))
const InviteLanding = lazy(() => import('@/pages/auth/InviteLanding'))
const PlatformDashboard = lazy(() => import('@/pages/PlatformDashboard'))
const PlatformApplicationDetail = lazy(() => import('@/pages/PlatformApplicationDetail'))
const ChurchDeletion = lazy(() => import('@/pages/platform/ChurchDeletion'))
const DeletedChurchesHistory = lazy(() => import('@/pages/platform/DeletedChurchesHistory'))
const ApplicationStatus = lazy(() => import('@/pages/ApplicationStatus'))
const NotFound = lazy(() => import('@/pages/NotFound'))
const Forbidden = lazy(() => import('@/pages/Forbidden'))
const ServerError = lazy(() => import('@/pages/ServerError'))

const AdminDashboard = lazy(() => import('@/pages/admin/Dashboard'))
const AdminUsers = lazy(() => import('@/pages/admin/Users'))
const AdminUserDetail = lazy(() => import('@/pages/admin/UserDetail'))
const StructureManagement = lazy(() => import('@/pages/admin/StructureManagement'))
const StageDetail = lazy(() => import('@/pages/admin/StageDetail'))
const ClasseDetail = lazy(() => import('@/pages/admin/ClasseDetail'))
const AdminQRManagement = lazy(() => import('@/pages/admin/QRManagement'))
const AdminEvents = lazy(() => import('@/pages/admin/Events'))
const AdminEventDetail = lazy(() => import('@/pages/admin/EventDetail'))
const AdminLeaderboard = lazy(() => import('@/pages/admin/Leaderboard'))
const AdminPasswordResetRequests = lazy(() => import('@/pages/admin/PasswordResetRequests'))

const Profile = lazy(() => import('@/pages/Profile'))
const ServantProfileUpdateRequests = lazy(() => import('@/pages/servant/ProfileUpdateRequests'))

const ServantDashboard = lazy(() => import('@/pages/servant/Dashboard'))
const ServantMembers = lazy(() => import('@/pages/servant/Members'))
const ServantMemberDetail = lazy(() => import('@/pages/servant/MemberDetail'))
const ServantScanQR = lazy(() => import('@/pages/servant/ScanQR'))
const ServantQRInvites = lazy(() => import('@/pages/servant/QRInvites'))
const ServantEvents = lazy(() => import('@/pages/servant/Events'))
const ServantMyAssignedEvents = lazy(() => import('@/pages/servant/MyAssignedEvents'))
const ServantEventDetail = lazy(() => import('@/pages/admin/EventDetail'))
const ServantAttendance = lazy(() => import('@/pages/servant/Attendance'))
const ServantLeaderboard = lazy(() => import('@/pages/servant/Leaderboard'))

const MemberDashboard = lazy(() => import('@/pages/member/Dashboard'))
const MemberAttendance = lazy(() => import('@/pages/member/Attendance'))
const MemberPointsPages = lazy(() => import('@/pages/member/Points'))
const MemberEvents = lazy(() => import('@/pages/member/Events'))
const MemberEventDetail = lazy(() => import('@/pages/member/EventDetail'))
const MemberMyQR = lazy(() => import('@/pages/member/MyQR'))
const MemberLeaderboard = lazy(() => import('@/pages/member/Leaderboard'))
const MemberSpiritualLog = lazy(() => import('@/pages/member/SpiritualLog'))
const FeedbackSubmit = lazy(() => import('@/pages/FeedbackSubmit'))
const FeedbackManagement = lazy(() => import('@/pages/FeedbackManagement'))
const VerseManagement = lazy(() => import('@/pages/VerseManagement'))
const AbsentMembers = lazy(() => import('@/pages/AbsentMembers'))
const AttendanceContextManagement = lazy(() => import('@/pages/AttendanceContextManagement'))

function OfflineBannerWrapper() {
  const { isOnline } = useOffline()
  const { pendingCount } = useSync()
  return <OfflineBanner isOnline={isOnline} pendingCount={pendingCount} />
}

function PublicPage({ children }: { children: ReactNode }) {
  return <Suspense fallback={FullPageSpinner}>{children}</Suspense>
}

function RoutePrefetcher() {
  const { user } = useAuth()

  useEffect(() => {
    if (!user) return

    prefetchRoutesWhenIdle([
      // High-frequency admin routes
      { importFn: () => import('@/pages/admin/Dashboard'), key: 'admin-dashboard' },
      { importFn: () => import('@/pages/admin/Users'), key: 'admin-users' },
      { importFn: () => import('@/pages/admin/PasswordResetRequests'), key: 'admin-password-reset' },
      { importFn: () => import('@/pages/servant/ProfileUpdateRequests'), key: 'admin-profile-update' },
      { importFn: () => import('@/pages/admin/Events'), key: 'admin-events' },
      { importFn: () => import('@/pages/servant/Attendance'), key: 'admin-attendance' },
      // High-frequency servant routes
      { importFn: () => import('@/pages/servant/Dashboard'), key: 'servant-dashboard' },
      { importFn: () => import('@/pages/servant/Members'), key: 'servant-members' },
      { importFn: () => import('@/pages/servant/Events'), key: 'servant-events' },
      // High-frequency member routes
      { importFn: () => import('@/pages/member/Dashboard'), key: 'member-dashboard' },
      { importFn: () => import('@/pages/member/Attendance'), key: 'member-attendance' },
      { importFn: () => import('@/pages/member/Events'), key: 'member-events' },
    ], 150)

    // Preheat the in-memory request cache for the user's role so the very
    // first visit to a page is served from cache (cache HIT) instead of
    // waiting for route-chunk download + all API round-trips.
    const timer = window.setTimeout(() => preheatData(user.role), 1200)
    return () => window.clearTimeout(timer)
  }, [user])

  return null
}

function RouteTimer() {
  const location = useLocation()
  const prevPath = useRef(location.pathname)
  const navStart = useRef<number>(0)

  useEffect(() => {
    navStart.current = Date.now()
  }, [])

  useEffect(() => {
    const from = prevPath.current
    const to = location.pathname
    if (from !== to) {
      const duration = Date.now() - navStart.current
      recordRouteTransition(from, to, duration)
      prevPath.current = to
    }
    navStart.current = Date.now()
  }, [location.pathname])

  return null
}

export default function App() {

  return (
    <BrowserRouter>
      <ThemeProvider>
        <Toaster
          position="top-center"
          toastOptions={{
            duration: 3000,
            style: { borderRadius: '12px', padding: '12px 16px', fontSize: '14px' },
          }}
        />
        <AuthProvider>
          <OfflineBannerWrapper />
          <RoutePrefetcher />
          <RouteTimer />
          <Routes>
              <Route path="/" element={<PublicPage><Landing /></PublicPage>} />
              <Route path="/join" element={<PublicPage><JoinNow /></PublicPage>} />
              <Route path="/register" element={<PublicPage><InviteRegister /></PublicPage>} />
              <Route path="/invite/:token" element={<PublicPage><InviteLanding /></PublicPage>} />
              <Route path="/login" element={<PublicPage><Login /></PublicPage>} />
              <Route path="/forgot-password" element={<PublicPage><ForgotPassword /></PublicPage>} />
              <Route path="/verify-email" element={<PublicPage><VerifyEmail /></PublicPage>} />
              <Route path="/chconfirmation777" element={<PublicPage><PlatformLogin /></PublicPage>} />
              <Route path="/application-status" element={<PublicPage><ApplicationStatus /></PublicPage>} />
              <Route path="/pending" element={<PublicPage><ApplicationStatus /></PublicPage>} />
              <Route path="/rejected" element={<PublicPage><ApplicationStatus /></PublicPage>} />

              <Route element={<AppLayout allowedRoles={['platform_admin']} />}>
                <Route path="/platform" element={<PlatformDashboard />} />
                <Route path="/platform/applications/:id" element={<PlatformApplicationDetail />} />
                <Route path="/platform/churches" element={<ChurchDeletion />} />
                <Route path="/platform/churches/deleted-history" element={<DeletedChurchesHistory />} />
              </Route>

              <Route element={<AppLayout allowedRoles={['admin', 'assistant_admin']} />}>
                <Route path="/admin" element={<AdminDashboard />} />
                <Route path="/admin/users" element={<AdminUsers />} />
                <Route path="/admin/users/:id" element={<AdminUserDetail />} />
                <Route path="/admin/structure" element={<StructureManagement />} />
                <Route path="/admin/stages/:id" element={<StageDetail />} />
                <Route path="/admin/classes/:id" element={<ClasseDetail />} />
                <Route path="/admin/qr" element={<AdminQRManagement />} />
                <Route path="/admin/events" element={<AdminEvents />} />
                <Route path="/admin/events/:id" element={<AdminEventDetail />} />
                <Route path="/admin/attendance" element={<ServantAttendance />} />
                <Route path="/admin/feedback" element={<FeedbackManagement />} />
                <Route path="/admin/verses" element={<VerseManagement />} />
                <Route path="/admin/absent-members" element={<AbsentMembers />} />
                <Route path="/admin/attendance-contexts" element={<AttendanceContextManagement />} />
                <Route path="/admin/leaderboard" element={<AdminLeaderboard />} />
                <Route path="/admin/password-reset-requests" element={<AdminPasswordResetRequests />} />
                <Route path="/admin/profile" element={<Profile />} />
                <Route path="/admin/profile-update-requests" element={<ServantProfileUpdateRequests />} />

                <Route path="/assistant-admin" element={<AdminDashboard />} />
                <Route path="/assistant-admin/users" element={<AdminUsers />} />
                <Route path="/assistant-admin/users/:id" element={<AdminUserDetail />} />
                <Route path="/assistant-admin/structure" element={<StructureManagement />} />
                <Route path="/assistant-admin/stages/:id" element={<StageDetail />} />
                <Route path="/assistant-admin/classes/:id" element={<ClasseDetail />} />
                <Route path="/assistant-admin/qr" element={<AdminQRManagement />} />
                <Route path="/assistant-admin/events" element={<AdminEvents />} />
                <Route path="/assistant-admin/events/:id" element={<AdminEventDetail />} />
                <Route path="/assistant-admin/attendance" element={<ServantAttendance />} />
                <Route path="/assistant-admin/feedback" element={<FeedbackManagement />} />
                <Route path="/assistant-admin/verses" element={<VerseManagement />} />
                <Route path="/assistant-admin/absent-members" element={<AbsentMembers />} />
                <Route path="/assistant-admin/attendance-contexts" element={<AttendanceContextManagement />} />
                <Route path="/assistant-admin/leaderboard" element={<AdminLeaderboard />} />
                <Route path="/assistant-admin/password-reset-requests" element={<AdminPasswordResetRequests />} />
                <Route path="/assistant-admin/profile" element={<Profile />} />
                <Route path="/assistant-admin/profile-update-requests" element={<ServantProfileUpdateRequests />} />
              </Route>

              <Route element={<AppLayout allowedRoles={['servant']} />}>
                <Route path="/servant" element={<ServantDashboard />} />
                <Route path="/servant/members" element={<ServantMembers />} />
                <Route path="/servant/members/:id" element={<ServantMemberDetail />} />
                <Route path="/servant/scan" element={<ServantScanQR />} />
                <Route path="/servant/qr" element={<ServantQRInvites />} />
                <Route path="/servant/events" element={<ServantEvents />} />
                <Route path="/servant/my-assigned-events" element={<ServantMyAssignedEvents />} />
                <Route path="/servant/events/:id" element={<ServantEventDetail />} />
                <Route path="/servant/attendance" element={<ServantAttendance />} />
                <Route path="/servant/feedback" element={<FeedbackManagement />} />
                <Route path="/servant/absent-members" element={<AbsentMembers />} />
                <Route path="/servant/attendance-contexts" element={<AttendanceContextManagement />} />
                <Route path="/servant/leaderboard" element={<ServantLeaderboard />} />
                <Route path="/servant/profile" element={<Profile />} />
                <Route path="/servant/profile-update-requests" element={<ServantProfileUpdateRequests />} />
              </Route>

              <Route element={<AppLayout allowedRoles={['member']} />}>
                <Route path="/member" element={<MemberDashboard />} />
                <Route path="/member/attendance" element={<MemberAttendance />} />
                <Route path="/member/points" element={<MemberPointsPages />} />
                <Route path="/member/events" element={<MemberEvents />} />
                <Route path="/member/events/:id" element={<MemberEventDetail />} />
                <Route path="/member/qr" element={<MemberMyQR />} />
                <Route path="/member/feedback" element={<FeedbackSubmit />} />
                <Route path="/member/leaderboard" element={<MemberLeaderboard />} />
                <Route path="/member/spiritual-log" element={<MemberSpiritualLog />} />
                <Route path="/member/profile" element={<Profile />} />
              </Route>

              <Route path="/403" element={<PublicPage><Forbidden /></PublicPage>} />
              <Route path="/500" element={<PublicPage><ServerError /></PublicPage>} />
              <Route path="*" element={<PublicPage><NotFound /></PublicPage>} />
            </Routes>
        </AuthProvider>
      </ThemeProvider>
    </BrowserRouter>
  )
}
