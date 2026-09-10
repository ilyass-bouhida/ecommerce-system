import { useEffect, useState } from 'react'
import http from '../api/http.js'

export default function ActivityPage() {
  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState(null)
  const [search, setSearch] = useState('')
  const [action, setAction] = useState('')
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    const t = setTimeout(load, 400)
    return () => clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search, action, page])

  async function load() {
    setLoading(true)
    setError('')
    try {
      const { data } = await http.get('/api/v1/audit-logs', {
        params: { search: search || undefined, action: action || undefined, page },
      })
      setRows(data.data)
      setMeta(data.meta)
    } catch {
      setError('Unable to load activity.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div>
      <h1 className="page-title">Activity</h1>

      <div className="filters">
        <input
          className="input"
          type="search"
          placeholder="Search action, entity, IP…"
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1) }}
        />
        <select className="input" value={action} onChange={(e) => { setAction(e.target.value); setPage(1) }}>
          <option value="">All actions</option>
          <option value="LOGIN_SUCCESS">LOGIN_SUCCESS</option>
          <option value="LOGIN_FAILED">LOGIN_FAILED</option>
          <option value="LOGOUT">LOGOUT</option>
          <option value="USER_CREATED">USER_CREATED</option>
          <option value="USER_UPDATED">USER_UPDATED</option>
          <option value="USER_DEACTIVATED">USER_DEACTIVATED</option>
          <option value="USER_ACTIVATED">USER_ACTIVATED</option>
          <option value="TOKEN_REVOKED">TOKEN_REVOKED</option>
        </select>
      </div>

      {error && <p className="alert alert-error">{error}</p>}

      <div className="card table-wrap">
        <table className="table">
          <thead>
            <tr>
              <th>Date</th><th>User</th><th>Action</th><th>Entity</th><th>Summary</th><th>IP</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan="6">Loading…</td></tr>
            ) : rows.length === 0 ? (
              <tr><td colSpan="6">No activity found.</td></tr>
            ) : rows.map((log) => (
              <tr key={log.id}>
                <td>{log.created_at ? new Date(log.created_at).toLocaleString() : '—'}</td>
                <td>{log.user ? `${log.user.name} (${log.user.email})` : '—'}</td>
                <td><span className="badge">{log.action}</span></td>
                <td>{log.entity_type ? `${log.entity_type} #${log.entity_id}` : '—'}</td>
                <td>{log.metadata ? safeSummary(log.metadata) : '—'}</td>
                <td>{log.ip_address || '—'}</td>
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

function safeSummary(metadata) {
  if (!metadata || typeof metadata !== 'object') return '—'
  const parts = []
  if (metadata.email) parts.push(metadata.email)
  if (metadata.role) parts.push(metadata.role)
  if (metadata.reason) parts.push(metadata.reason)
  return parts.length > 0 ? parts.join(' · ') : '—'
}
