import { Navigate } from 'react-router-dom'

/** @deprecated Use /application-status instead */
export default function PendingDashboard() {
  return <Navigate to="/application-status" replace />
}
