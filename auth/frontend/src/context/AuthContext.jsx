import { createContext, useCallback, useContext, useEffect, useState } from 'react'
import http from '../api/http.js'

const AuthContext = createContext(null)

function friendlyError(err, fallback) {
  const data = err?.response?.data
  if (data?.message) return data.message
  if (data?.errors) {
    const first = Object.values(data.errors).flat()[0]
    if (first) return first
  }
  return fallback
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)

  const loadCurrentUser = useCallback(async () => {
    try {
      const { data } = await http.get('/api/v1/auth/me')
      setUser(data.user)
      return data.user
    } catch {
      setUser(null)
      return null
    }
  }, [])

  useEffect(() => {
    loadCurrentUser().finally(() => setLoading(false))
  }, [loadCurrentUser])

  const login = useCallback(async (email, password) => {
    try {
      const { data } = await http.post('/api/v1/auth/login', { email, password })
      setUser(data.user)
      return data.user
    } catch (err) {
      throw new Error(friendlyError(err, 'Unable to sign in. Please try again.'))
    }
  }, [])

  const logout = useCallback(async () => {
    try {
      await http.post('/api/v1/auth/logout')
    } catch {
      // Token may already be gone; still clear local state.
    }
    setUser(null)
  }, [])

  return (
    <AuthContext.Provider value={{ user, loading, login, logout, loadCurrentUser }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider')
  return ctx
}
