import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import http from '../api/http.js'
import { useAuth } from '../context/AuthContext.jsx'

export default function DashboardPage() {
  const { user } = useAuth()
  const [health, setHealth] = useState(null)
  const isOwner = user?.role === 'OWNER'

  useEffect(() => {
    http.get('/api/v1/health')
      .then(({ data }) => setHealth(data))
      .catch(() => setHealth({ status: 'error' }))
  }, [])

  return (
    <div>
      <h1 className="page-title">Welcome, {user?.name}</h1>
      <p><span className="badge">{user?.role}</span></p>
      <p className="muted">
        Auth service status: {health ? health.status : 'checking…'}
      </p>

      {isOwner && (
        <div className="cards">
          <Link className="card link-card" to="/users">
            <h2>Users</h2>
            <p className="muted">Manage accounts.</p>
          </Link>
          <Link className="card link-card" to="/activity">
            <h2>Activity</h2>
            <p className="muted">Review the audit log.</p>
          </Link>
        </div>
      )}
    </div>
  )
}
