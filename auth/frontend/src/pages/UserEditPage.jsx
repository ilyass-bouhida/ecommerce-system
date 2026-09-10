import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import http from '../api/http.js'

export default function UserEditPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [form, setForm] = useState({ name: '', email: '', role: 'STAFF', password: '', password_confirmation: '' })
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    http.get(`/api/v1/users/${id}`)
      .then(({ data }) => setForm({
        name: data.user.name,
        email: data.user.email,
        role: data.user.role,
        password: '',
        password_confirmation: '',
      }))
      .catch(() => setError('Unable to load user.'))
  }, [id])

  function set(key, value) {
    setForm((f) => ({ ...f, [key]: value }))
  }

  async function onSubmit(e) {
    e.preventDefault()
    setError('')
    setBusy(true)
    try {
      const payload = { name: form.name, email: form.email, role: form.role }
      if (form.password) {
        payload.password = form.password
        payload.password_confirmation = form.password_confirmation
      }
      await http.patch(`/api/v1/users/${id}`, payload)
      navigate(`/users/${id}`)
    } catch (err) {
      const d = err?.response?.data
      setError(d?.message || (d?.errors ? Object.values(d.errors).flat().join(' ') : 'Unable to save user.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <h1 className="page-title">Edit User</h1>
      <form className="card form" onSubmit={onSubmit}>
        {error && <p className="alert alert-error" role="alert">{error}</p>}
        <label className="field"><span>Name</span>
          <input value={form.name} onChange={(e) => set('name', e.target.value)} required />
        </label>
        <label className="field"><span>Email</span>
          <input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} required />
        </label>
        <label className="field"><span>Role</span>
          <select value={form.role} onChange={(e) => set('role', e.target.value)}>
            <option value="STAFF">STAFF</option>
            <option value="ADMIN">ADMIN</option>
            <option value="OWNER">OWNER</option>
          </select>
        </label>
        <label className="field"><span>New Password (optional)</span>
          <input type="password" autoComplete="new-password" value={form.password} onChange={(e) => set('password', e.target.value)} />
        </label>
        <label className="field"><span>Confirm New Password</span>
          <input type="password" autoComplete="new-password" value={form.password_confirmation} onChange={(e) => set('password_confirmation', e.target.value)} />
        </label>
        <div className="form-actions">
          <button type="submit" className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Save'}</button>
          <Link className="btn btn-ghost" to={`/users/${id}`}>Cancel</Link>
        </div>
      </form>
    </div>
  )
}
