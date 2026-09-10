import { useState } from 'react'
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext.jsx'

export default function AppLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const isOwner = user?.role === 'OWNER'

  async function handleLogout() {
    await logout()
    navigate('/login')
  }

  return (
    <div className="shell">
      <aside className={`sidebar${open ? ' open' : ''}`}>
        <div className="brand">
          <Link to="/dashboard" onClick={() => setOpen(false)}>Auth Admin</Link>
        </div>
        <nav className="nav" onClick={() => setOpen(false)}>
          <NavLink to="/dashboard">Dashboard</NavLink>
          {isOwner && <NavLink to="/users">Users</NavLink>}
          {isOwner && <NavLink to="/activity">Activity</NavLink>}
          {!isOwner && <span className="nav-note">Users and Activity are OWNER only.</span>}
        </nav>
        <div className="sidebar-foot">
          <button type="button" className="btn btn-ghost" onClick={handleLogout}>
            Logout
          </button>
        </div>
      </aside>

      <div className="main">
        <header className="topbar">
          <button
            type="button"
            className="btn btn-ghost menu-btn"
            onClick={() => setOpen((v) => !v)}
            aria-label="Toggle navigation"
          >
            ☰
          </button>
          <div className="topbar-user">
            <span className="user-name">{user?.name}</span>
            <span className="badge">{user?.role}</span>
          </div>
        </header>
        <main className="content">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
