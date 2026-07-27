import { createContext, useContext, useState, useEffect, ReactNode } from 'react'
import { authApi, organizationsApi, invitationsApi, User, Organization, OrganizationRole } from '../services/api'

interface AuthContextType {
  user: User | null
  organizations: Organization[]
  currentOrganization: Organization | null
  currentRole: OrganizationRole | null
  isAuthenticated: boolean
  loading: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  refreshUser: () => Promise<void>
  switchOrganization: (id: number) => Promise<void>
  acceptInvitation: (
    token: string,
    data?: { name: string; password: string; password_confirmation: string }
  ) => Promise<void>
  hasRole: (...roles: OrganizationRole[]) => boolean
}

const AuthContext = createContext<AuthContextType | undefined>(undefined)

function persistUser(user: User) {
  localStorage.setItem('user', JSON.stringify(user))
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const token = localStorage.getItem('token')
    const savedUser = localStorage.getItem('user')

    if (token && savedUser) {
      setUser(JSON.parse(savedUser))
      // Verify token is still valid
      authApi.getUser()
        .then((res) => {
          setUser(res.data)
          persistUser(res.data)
        })
        .catch(() => {
          localStorage.removeItem('token')
          localStorage.removeItem('user')
          setUser(null)
        })
        .finally(() => setLoading(false))
    } else {
      setLoading(false)
    }
  }, [])

  const login = async (email: string, password: string) => {
    const response = await authApi.login(email, password)
    const { user, token } = response.data
    localStorage.setItem('token', token)
    persistUser(user)
    setUser(user)
  }

  const logout = async () => {
    try {
      await authApi.logout()
    } finally {
      localStorage.removeItem('token')
      localStorage.removeItem('user')
      setUser(null)
    }
  }

  const refreshUser = async () => {
    const res = await authApi.getUser()
    setUser(res.data)
    persistUser(res.data)
  }

  // Hard reload after switching: NavigationContext and every page fetch
  // org-scoped data on mount, so a full reset is correct by construction.
  const switchOrganization = async (id: number) => {
    await organizationsApi.switch(id)
    window.location.assign('/app/')
  }

  const acceptInvitation = async (
    token: string,
    data?: { name: string; password: string; password_confirmation: string }
  ) => {
    const response = await invitationsApi.accept(token, data)

    // The registration path returns credentials, mirroring login.
    if (response.data.token && response.data.user) {
      localStorage.setItem('token', response.data.token)
      persistUser(response.data.user)
      setUser(response.data.user)
    } else {
      await refreshUser()
    }
  }

  const organizations = user?.organizations ?? []
  const currentOrganization = user?.current_organization ?? null
  const currentRole = currentOrganization?.role ?? null

  const hasRole = (...roles: OrganizationRole[]) =>
    currentRole !== null && roles.includes(currentRole)

  return (
    <AuthContext.Provider
      value={{
        user,
        organizations,
        currentOrganization,
        currentRole,
        isAuthenticated: !!user,
        loading,
        login,
        logout,
        refreshUser,
        switchOrganization,
        acceptInvitation,
        hasRole,
      }}
    >
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const context = useContext(AuthContext)
  if (context === undefined) {
    throw new Error('useAuth must be used within an AuthProvider')
  }
  return context
}
