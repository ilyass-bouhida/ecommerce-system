import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import http from '../api/http.js'

export default function UserDetailsPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [user, setUser] = useState(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  useEffect(() => {
    http.get(`/api/v1/users/${id}`)
      .then(({ data }) => setUser(data.user))
      .catch(() => setError('Unable to load user.'))
  }, [id])

  async function deactivate() {
    if (!window.confirm('Deactivate this user? Their tokens will be revoked.')) return
    try {
      const { data } = await http.delete(`/api/v1/users/${id}`)
      setUser(data.user)
      setNotice('User deactivated.')
    } catch (err) {
      setNotice(err?.response?.data?.message || 'Unable to deactivate user.')
    }
  }

  async function activate() {
    try {
      const { data } = await http.post(`/api/v1/users/${id}/activate`)
      setUser(data.user)
      setNotice('User activated.')
    } catch {
      setNotice('Unable to activate user.')
    }
  }

  if (error) return <p className="alert alert-error">{error}</p>
  if (!user) return <p>Loading…</p>

  return (
    <div>
      <h1 className="page-title">{user.name}</h1>
      {notice && <p className="alert">{notice}</p>}
      <div className="card">
        <dl className="details">
          <dt>Email</dt><dd>{user.email}</dd>
          <dt>Role</dt><dd><span className="badge">{user.role}</span></dd>
          <dt>Status</dt><dd>{user.is_active ? 'Active' : 'Inactive'}</dd>
          <dt>Last Login</dt><dd>{user.last_login_at ? new Date(user.last_login_at).toLocaleString() : '—'}</dd>
          <dt>Created</dt><dd>{user.created_at ? new Date(user.created_at).toLocaleString() : '—'}</dd>
        </dl>
        <div className="form-actions">
          <Link className="btn btn-primary" to={`/users/${user.id}/edit`}>Edit</Link>
          {user.is_active
            ? <button type="button" className="btn btn-danger" onClick={deactivate}>Deactivate</button>
            : <button type="button" className="btn btn-primary" onClick={activate}>Activate</button>}
          <button type="button" className="btn btn-ghost" onClick={() => navigate('/users')}>Back</button>
        </div>
      </div>
    </div>
  )
}
