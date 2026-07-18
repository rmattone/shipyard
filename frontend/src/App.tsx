import { Routes, Route, Navigate } from 'react-router-dom'
import { Toaster } from '@/components/ui/sonner'
import { AuthProvider, useAuth } from './hooks/useAuth'
import { NavigationProvider } from '@/contexts/NavigationContext'
import { ThemeProvider } from '@/contexts/ThemeContext'
import { LoadingSpinner } from '@/components/custom'
import Layout from './components/Layout'
import Login from './pages/Login'
import NotFound from './pages/NotFound'

// Organization level pages
import OrganizationOverview from './pages/OrganizationOverview'
import ServerNew from './pages/servers/ServerNew'
import Settings from './pages/settings/Settings'
import GitProviderNew from './pages/git-providers/GitProviderNew'
import GitProviderDetail from './pages/git-providers/GitProviderDetail'

// Server level pages
import ServerOverview from './pages/servers/ServerOverview'
import ServerSoftware from './pages/servers/ServerSoftware'
import ServerDatabases from './pages/servers/ServerDatabases'
import DatabaseDetail from './pages/servers/DatabaseDetail'
import ServerScheduler from './pages/servers/ServerScheduler'
import ServerDaemons from './pages/servers/ServerDaemons'
import ServerSettings from './pages/servers/ServerSettings'
import AppNew from './pages/apps/AppNew'

// App level pages
import AppOverview from './pages/apps/AppOverview'
import AppDeployments from './pages/apps/AppDeployments'
import AppDomains from './pages/apps/AppDomains'
import AppLogs from './pages/apps/AppLogs'
import AppScheduler from './pages/apps/AppScheduler'
import AppDaemons from './pages/apps/AppDaemons'
import AppSettings from './pages/apps/AppSettings'
import DeploymentDetail from './pages/apps/DeploymentDetail'

function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated, loading } = useAuth()

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />
  }

  return <>{children}</>
}

function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route
        path="/"
        element={
          <ProtectedRoute>
            <NavigationProvider>
              <Layout />
            </NavigationProvider>
          </ProtectedRoute>
        }
      >
        {/* Organization level */}
        <Route index element={<OrganizationOverview />} />
        <Route path="servers/new" element={<ServerNew />} />
        <Route path="settings" element={<Settings />} />
        <Route path="settings/git-providers/new" element={<GitProviderNew />} />
        <Route path="settings/git-providers/:id" element={<GitProviderDetail />} />

        {/* Server level */}
        <Route path="servers/:id" element={<ServerOverview />} />
        <Route path="servers/:id/software" element={<ServerSoftware />} />
        <Route path="servers/:id/databases" element={<ServerDatabases />} />
        <Route path="servers/:id/databases/:databaseId" element={<DatabaseDetail />} />
        <Route path="servers/:id/scheduler" element={<ServerScheduler />} />
        <Route path="servers/:id/daemons" element={<ServerDaemons />} />
        <Route path="servers/:id/settings" element={<ServerSettings />} />
        <Route path="servers/:serverId/apps/new" element={<AppNew />} />

        {/* App level */}
        <Route path="apps/:id" element={<AppOverview />} />
        <Route path="apps/:id/deployments" element={<AppDeployments />} />
        <Route path="apps/:id/domains" element={<AppDomains />} />
        <Route path="apps/:id/logs" element={<AppLogs />} />
        <Route path="apps/:id/scheduler" element={<AppScheduler />} />
        <Route path="apps/:id/daemons" element={<AppDaemons />} />
        <Route path="apps/:id/settings" element={<AppSettings />} />
        <Route path="apps/:id/deployments/:deploymentId" element={<DeploymentDetail />} />

        {/* Catch-all: unknown paths used to render a blank page */}
        <Route path="*" element={<NotFound />} />
      </Route>
    </Routes>
  )
}

function App() {
  return (
    <ThemeProvider>
      <AuthProvider>
        <AppRoutes />
        <Toaster richColors position="top-right" />
      </AuthProvider>
    </ThemeProvider>
  )
}

export default App
