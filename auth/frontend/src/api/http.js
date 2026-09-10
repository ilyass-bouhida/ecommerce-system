import axios from 'axios'

// Single shared Axios client. Opaque-token cookie auth: the HttpOnly
// auth_token cookie is attached automatically (withCredentials) and the
// raw token is never exposed to JavaScript — no localStorage/sessionStorage.
const http = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8001',
  withCredentials: true,
  headers: {
    Accept: 'application/json',
  },
})

export default http
