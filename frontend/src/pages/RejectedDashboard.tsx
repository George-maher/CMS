import { Navigate } from 'react-router-dom'

/** @deprecated Use /application-status instead */
export default function RejectedDashboard() {
  return <Navigate to="/application-status" replace />
}
