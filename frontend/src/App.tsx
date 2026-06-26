import { BrowserRouter, Routes, Route } from 'react-router-dom'
import { Suspense, lazy } from 'react'
import { Toaster } from 'react-hot-toast'
import { AuthProvider } from '@/contexts/AuthContext'
import { ThemeProvider } from '@/contexts/ThemeContext'
import AppLayout from '@/components/layout/AppLayout'

const Landing = lazy(() => import('@/pages/Landing'))
const JoinNow = lazy(() => import('@/pages/JoinNow'))
const Login = lazy(() => import('@/pages/auth/Login'))
const PlatformLogin = lazy(() => import('@/pages/auth/PlatformLogin'))
const ForgotPassword = lazy(() => import('@/pages/auth/ForgotPassword'))
const ResetPassword = lazy(() => import('@/pages/auth/ResetPassword'))
const ResetPasswordFromRequest = lazy(() => import('@/pages/auth/ResetPasswordFromRequest'))
const VerifyEmail = lazy(() => import('@/pages/auth/VerifyEmail'))
const InviteRegister = lazy(() => import('@/pages/auth/InviteRegister'))
const InviteLanding = lazy(() => import('@/pages/auth/InviteLanding'))
const PlatformDashboard = lazy(() => import('@/pages/PlatformDashboard'))
const PlatformApplicationDetail = lazy(() => import('@/pages/PlatformApplicationDetail'))
const ChurchDeletion = lazy(() => import('@/pages/platform/ChurchDeletion'))
const PendingDashboard = lazy(() => import('@/pages/PendingDashboard'))
const RejectedDashboard = lazy(() => import('@/pages/RejectedDashboard'))
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
const AdminLeaderboard = lazy(() => import('@/pages/admin/Leaderboard'))
const AdminPasswordResetRequests = lazy(() => import('@/pages/admin/PasswordResetRequests'))

const ServantDashboard = lazy(() => import('@/pages/servant/Dashboard'))
const ServantMembers = lazy(() => import('@/pages/servant/Members'))
const ServantMemberDetail = lazy(() => import('@/pages/servant/MemberDetail'))
const ServantScanQR = lazy(() => import('@/pages/servant/ScanQR'))
const ServantQRInvites = lazy(() => import('@/pages/servant/QRInvites'))
const ServantEvents = lazy(() => import('@/pages/servant/Events'))
const ServantAttendance = lazy(() => import('@/pages/servant/Attendance'))
const ServantLeaderboard = lazy(() => import('@/pages/servant/Leaderboard'))

const MemberDashboard = lazy(() => import('@/pages/member/Dashboard'))
const MemberAttendance = lazy(() => import('@/pages/member/Attendance'))
const MemberPointsPages = lazy(() => import('@/pages/member/Points'))
const MemberEvents = lazy(() => import('@/pages/member/Events'))
const MemberEventDetail = lazy(() => import('@/pages/member/EventDetail'))
const MemberMyQR = lazy(() => import('@/pages/member/MyQR'))
const MemberLeaderboard = lazy(() => import('@/pages/member/Leaderboard'))
const FeedbackSubmit = lazy(() => import('@/pages/FeedbackSubmit'))
const FeedbackManagement = lazy(() => import('@/pages/FeedbackManagement'))
const VerseManagement = lazy(() => import('@/pages/VerseManagement'))
const AbsentMembers = lazy(() => import('@/pages/AbsentMembers'))
const AttendanceContextManagement = lazy(() => import('@/pages/AttendanceContextManagement'))

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
          <Suspense fallback={<div className="flex min-h-screen items-center justify-center"><div className="h-8 w-8 animate-spin rounded-full border-2 border-indigo-600 border-t-transparent" /></div>}>
            <Routes>
              <Route path="/" element={<Landing />} />
              <Route path="/join" element={<JoinNow />} />
              <Route path="/register" element={<InviteRegister />} />
              <Route path="/invite/:token" element={<InviteLanding />} />
              <Route path="/login" element={<Login />} />
              <Route path="/forgot-password" element={<ForgotPassword />} />
              <Route path="/reset-password" element={<ResetPassword />} />
              <Route path="/reset-password-request" element={<ResetPasswordFromRequest />} />
              <Route path="/verify-email" element={<VerifyEmail />} />
              <Route path="/chconfirmation777" element={<PlatformLogin />} />
              <Route path="/pending" element={<PendingDashboard />} />
              <Route path="/rejected" element={<RejectedDashboard />} />

              <Route element={<AppLayout allowedRoles={['platform_admin']} />}>
                <Route path="/platform" element={<PlatformDashboard />} />
                <Route path="/platform/applications/:id" element={<PlatformApplicationDetail />} />
                <Route path="/platform/churches" element={<ChurchDeletion />} />
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
                <Route path="/admin/attendance" element={<ServantAttendance />} />
                <Route path="/admin/feedback" element={<FeedbackManagement />} />
                <Route path="/admin/verses" element={<VerseManagement />} />
                <Route path="/admin/absent-members" element={<AbsentMembers />} />
                <Route path="/admin/attendance-contexts" element={<AttendanceContextManagement />} />
                <Route path="/admin/leaderboard" element={<AdminLeaderboard />} />
                <Route path="/admin/password-reset-requests" element={<AdminPasswordResetRequests />} />

                <Route path="/assistant-admin" element={<AdminDashboard />} />
                <Route path="/assistant-admin/users" element={<AdminUsers />} />
                <Route path="/assistant-admin/users/:id" element={<AdminUserDetail />} />
                <Route path="/assistant-admin/structure" element={<StructureManagement />} />
                <Route path="/assistant-admin/stages/:id" element={<StageDetail />} />
                <Route path="/assistant-admin/classes/:id" element={<ClasseDetail />} />
                <Route path="/assistant-admin/qr" element={<AdminQRManagement />} />
                <Route path="/assistant-admin/events" element={<AdminEvents />} />
                <Route path="/assistant-admin/attendance" element={<ServantAttendance />} />
                <Route path="/assistant-admin/feedback" element={<FeedbackManagement />} />
                <Route path="/assistant-admin/verses" element={<VerseManagement />} />
                <Route path="/assistant-admin/absent-members" element={<AbsentMembers />} />
                <Route path="/assistant-admin/attendance-contexts" element={<AttendanceContextManagement />} />
                <Route path="/assistant-admin/leaderboard" element={<AdminLeaderboard />} />
                <Route path="/assistant-admin/password-reset-requests" element={<AdminPasswordResetRequests />} />
              </Route>

              <Route element={<AppLayout allowedRoles={['servant']} />}>
                <Route path="/servant" element={<ServantDashboard />} />
                <Route path="/servant/members" element={<ServantMembers />} />
                <Route path="/servant/members/:id" element={<ServantMemberDetail />} />
                <Route path="/servant/scan" element={<ServantScanQR />} />
                <Route path="/servant/qr" element={<ServantQRInvites />} />
                <Route path="/servant/events" element={<ServantEvents />} />
                <Route path="/servant/attendance" element={<ServantAttendance />} />
                <Route path="/servant/feedback" element={<FeedbackManagement />} />
                <Route path="/servant/absent-members" element={<AbsentMembers />} />
                <Route path="/servant/attendance-contexts" element={<AttendanceContextManagement />} />
                <Route path="/servant/leaderboard" element={<ServantLeaderboard />} />
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
              </Route>

              <Route path="/403" element={<Forbidden />} />
              <Route path="/500" element={<ServerError />} />
              <Route path="*" element={<NotFound />} />
            </Routes>
          </Suspense>
        </AuthProvider>
      </ThemeProvider>
    </BrowserRouter>
  )
}
