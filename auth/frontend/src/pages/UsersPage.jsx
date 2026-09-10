import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import http from '../api/http.js'

function useDebounced(value, delay = 400) {
  const [v, setV] = useState(value)
  useEffect(() => {
    const t = setTimeout(() => setV(value), delay)
    return () => clearTimeout(t)
  }, [value, delay])
  return v
}

export default function UsersPage() {
  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState(null)
  const [search, setSearch] = useState('')
  const [role, setRole] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const debouncedSearch = useDebounced(search)

  async function load() {
    setLoading(true)
    setError('')
    try {
      const { data } = await http.get('/api/v1/users', {
        params: { search: debouncedSearch || undefined, role: role || undefined, status: status || undefined, page },
      })
      setRows(data.data)
      setMeta(data.meta)
    } catch {
      setError('Unable to load users.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch, role, status, page])

  async function deactivate(id) {
    if (!window.confirm('Deactivate this user? Their tokens will be revoked.')) return
    setNotice('')
    try {
      await http.delete(`/api/v1/users/${id}`)
      setNotice('User deactivated.')
      load()
    } catch (err) {
      setNotice(err?.response?.data?.message || 'Unable to deactivate user.')
    }
  }

  async function activate(id) {
    setNotice('')
    try {
      await http.post(`/api/v1/users/${id}/activate`)
      setNotice('User activated.')
      load()
    } catch {
      setNotice('Unable to activate user.')
    }
  }

  return (
    <div>
      <div className="page-head">
        <h1 className="page-title">Users</h1>
        <Link className="btn btn-primary" to="/users/new">Add User</Link>
      </div>

      <div className="filters">
        <input
          className="input"
          type="search"
          placeholder="Search name or email…"
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1) }}
        />
        <select className="input" value={role} onChange={(e) => { setRole(e.target.value); setPage(1) }}>
          <option value="">All roles</option>
          <option value="OWNER">OWNER</option>
          <option value="ADMIN">ADMIN</option>
          <option value="STAFF">STAFF</option>
        </select>
        <select className="input" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>

      {notice && <p className="alert">{notice}</p>}
      {error && <p className="alert alert-error">{error}</p>}

      <div className="card table-wrap">
        <table className="table">
          <thead>
            <tr>
              <th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last Login</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan="6">Loading…</td></tr>
            ) : rows.length === 0 ? (
              <tr><td colSpan="6">No users found.</td></tr>
            ) : rows.map((u) => (
              <tr key={u.id}>
                <td>{u.name}</td>
                <td>{u.email}</td>
                <td><span className="badge">{u.role}</span></td>
                <td>{u.is_active ? 'Active' : 'Inactive'}</td>
                <td>{u.last_login_at ? new Date(u.last_login_at).toLocaleString() : '—'}</td>
                <td className="row-actions">
                  <Link to={`/users/${u.id}`}>View</Link>
                  <Link to={`/users/${u.id}/edit`}>Edit</Link>
                  {u.is_active
                    ? <button type="button" className="link-btn" onClick={() => deactivate(u.id)}>Deactivate</button>
                    : <button type="button" className="link-btn" onClick={() => activate(u.id)}>Activate</button>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {meta && meta.last_page > 1 && (
        <div className="pagination">
          <button type="button" className="btn btn-ghost" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</button>
          <span>Page {meta.current_page} of {meta.last_page} ({meta.total} total)</span>
          <button type="button" className="btn btn-ghost" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>Next</button>
        </div>
      )}
    </div>
  )
}
