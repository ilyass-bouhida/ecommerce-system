import { Navigate, Route, Routes } from 'react-router-dom'
import OwnerRoute from './components/OwnerRoute.jsx'
import ProtectedRoute from './components/ProtectedRoute.jsx'
import { AuthProvider } from './context/AuthContext.jsx'
import AppLayout from './layouts/AppLayout.jsx'
import ActivityPage from './pages/ActivityPage.jsx'
import DashboardPage from './pages/DashboardPage.jsx'
import { ForbiddenPage, NotFoundPage } from './pages/ErrorPages.jsx'
import LoginPage from './pages/LoginPage.jsx'
import UserCreatePage from './pages/UserCreatePage.jsx'
import UserDetailsPage from './pages/UserDetailsPage.jsx'
import UserEditPage from './pages/UserEditPage.jsx'
import UsersPage from './pages/UsersPage.jsx'

export default function App() {
  return (
    <AuthProvider>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/403" element={<ForbiddenPage />} />

        <Route element={<ProtectedRoute><AppLayout /></ProtectedRoute>}>
          <Route path="/" element={<Navigate to="/dashboard" replace />} />
          <Route path="/dashboard" element={<DashboardPage />} />
          <Route path="/users" element={<OwnerRoute><UsersPage /></OwnerRoute>} />
          <Route path="/users/new" element={<OwnerRoute><UserCreatePage /></OwnerRoute>} />
          <Route path="/users/:id" element={<OwnerRoute><UserDetailsPage /></OwnerRoute>} />
          <Route path="/users/:id/edit" element={<OwnerRoute><UserEditPage /></OwnerRoute>} />
          <Route path="/activity" element={<OwnerRoute><ActivityPage /></OwnerRoute>} />
        </Route>

        <Route path="*" element={<NotFoundPage />} />
      </Routes>
    </AuthProvider>
  )
}
