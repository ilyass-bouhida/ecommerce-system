import { Link } from 'react-router-dom'

export function ForbiddenPage() {
  return (
    <div className="center-page">
      <div className="card">
        <h1>403 — Forbidden</h1>
        <p className="muted">You do not have access to this page.</p>
        <p><Link to="/dashboard">Back to dashboard</Link></p>
      </div>
    </div>
  )
}

export function NotFoundPage() {
  return (
    <div className="center-page">
      <div className="card">
        <h1>404 — Not found</h1>
        <p className="muted">This page does not exist.</p>
        <p><Link to="/dashboard">Back to dashboard</Link></p>
      </div>
    </div>
  )
}
