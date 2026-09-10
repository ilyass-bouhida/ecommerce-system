import { Navigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext.jsx'

export default function OwnerRoute({ children }) {
  const { user, loading } = useAuth()

  if (loading) return <div className="center-page"><p>Loading…</p></div>
  if (!user) return <Navigate to="/login" replace />
  if (user.role !== 'OWNER') return <Navigate to="/403" replace />

  return children
}
