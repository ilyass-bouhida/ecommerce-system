import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import http from '../api/http.js'

export default function UserCreatePage() {
  const navigate = useNavigate()
  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '', role: 'STAFF' })
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  function set(key, value) {
    setForm((f) => ({ ...f, [key]: value }))
  }

  async function onSubmit(e) {
    e.preventDefault()
    setError('')
    setBusy(true)
    try {
      const { data } = await http.post('/api/v1/users', form)
      navigate(`/users/${data.user.id}`)
    } catch (err) {
      const d = err?.response?.data
      setError(d?.message || (d?.errors ? Object.values(d.errors).flat().join(' ') : 'Unable to create user.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <h1 className="page-title">New User</h1>
      <form className="card form" onSubmit={onSubmit}>
        {error && <p className="alert alert-error" role="alert">{error}</p>}
        <label className="field"><span>Name</span>
          <input value={form.name} onChange={(e) => set('name', e.target.value)} required />
        </label>
        <label className="field"><span>Email</span>
          <input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} required />
        </label>
        <label className="field"><span>Password</span>
          <input type="password" autoComplete="new-password" value={form.password} onChange={(e) => set('password', e.target.value)} required />
        </label>
        <label className="field"><span>Confirm Password</span>
          <input type="password" autoComplete="new-password" value={form.password_confirmation} onChange={(e) => set('password_confirmation', e.target.value)} required />
        </label>
        <label className="field"><span>Role</span>
          <select value={form.role} onChange={(e) => set('role', e.target.value)}>
            <option value="STAFF">STAFF</option>
            <option value="ADMIN">ADMIN</option>
            <option value="OWNER">OWNER</option>
          </select>
        </label>
        <div className="form-actions">
          <button type="submit" className="btn btn-primary" disabled={busy}>{busy ? 'Creating…' : 'Create User'}</button>
          <Link className="btn btn-ghost" to="/users">Cancel</Link>
        </div>
      </form>
    </div>
  )
}
