import { createContext, useContext, useState, useEffect, ReactNode, useCallback } from 'react'
import { useLocation } from 'react-router-dom'
import { Server, Application, serversApi, applicationsApi } from '@/services/api'

interface NavigationContextType {
  // Current selections
  currentServer: Server | null
  currentApp: Application | null

  // Lists for dropdowns
  servers: Server[]
  apps: Application[]

  // Loading states
  loadingServers: boolean
  loadingApps: boolean

  // Refresh functions
  refreshServers: () => Promise<Server[]>
  refreshApps: () => Promise<Application[]>
}

const NavigationContext = createContext<NavigationContextType | null>(null)

export function useNavigation() {
  const context = useContext(NavigationContext)
  if (!context) {
    throw new Error('useNavigation must be used within a NavigationProvider')
  }
  return context
}

export function NavigationProvider({ children }: { children: ReactNode }) {
  const location = useLocation()

  const [servers, setServers] = useState<Server[]>([])
  const [apps, setApps] = useState<Application[]>([])
  const [currentServer, setCurrentServer] = useState<Server | null>(null)
  const [currentApp, setCurrentApp] = useState<Application | null>(null)
  const [loadingServers, setLoadingServers] = useState(true)
  const [loadingApps, setLoadingApps] = useState(true)

  const refreshServers = useCallback(async () => {
    try {
      setLoadingServers(true)
      const response = await serversApi.list()
      setServers(response.data)
      return response.data
    } catch (error) {
      console.error('Failed to load servers:', error)
      return []
    } finally {
      setLoadingServers(false)
    }
  }, [])

  const refreshApps = useCallback(async () => {
    try {
      setLoadingApps(true)
      const response = await applicationsApi.list()
      setApps(response.data)
      return response.data
    } catch (error) {
      console.error('Failed to load apps:', error)
      return []
    } finally {
      setLoadingApps(false)
    }
  }, [])

  // Load servers and apps on mount
  useEffect(() => {
    refreshServers()
    refreshApps()
  }, [refreshServers, refreshApps])

  // Determine current server and app from URL
  useEffect(() => {
    const path = location.pathname

    // Check if we're on a server page
    const serverMatch = path.match(/\/servers\/(\d+)/)
    if (serverMatch) {
      const serverId = parseInt(serverMatch[1])
      const server = servers.find(s => s.id === serverId)
      if (server) {
        setCurrentServer(server)
      } else if (servers.length === 0) {
        // Servers not loaded yet, fetch directly
        serversApi.get(serverId).then(res => {
          setCurrentServer(res.data)
        }).catch(() => {
          setCurrentServer(null)
        })
      } else {
        setCurrentServer(null)
      }
      setCurrentApp(null)
      return
    }

    // Check if we're on an app page
    const appMatch = path.match(/\/apps\/(\d+)/)
    if (appMatch) {
      const appId = parseInt(appMatch[1])
      const app = apps.find(a => a.id === appId)

      if (app) {
        setCurrentApp(app)
        const server = servers.find(s => s.id === app.server_id)
        setCurrentServer(server || null)

        // If server not found in list, fetch it
        if (!server && app.server_id) {
          serversApi.get(app.server_id).then(res => {
            setCurrentServer(res.data)
          }).catch(() => {})
        }
      } else {
        // App not in list yet, fetch directly
        applicationsApi.get(appId).then(res => {
          const fetchedApp = res.data
          setCurrentApp(fetchedApp)

          // Also fetch the server
          if (fetchedApp.server_id) {
            const server = servers.find(s => s.id === fetchedApp.server_id)
            if (server) {
              setCurrentServer(server)
            } else {
              serversApi.get(fetchedApp.server_id).then(serverRes => {
                setCurrentServer(serverRes.data)
              }).catch(() => {
                setCurrentServer(null)
              })
            }
          }
        }).catch(() => {
          setCurrentApp(null)
          setCurrentServer(null)
        })
      }
      return
    }

    // Not on a specific server or app page
    setCurrentServer(null)
    setCurrentApp(null)
  }, [location.pathname, servers, apps])

  return (
    <NavigationContext.Provider
      value={{
        currentServer,
        currentApp,
        servers,
        apps,
        loadingServers,
        loadingApps,
        refreshServers,
        refreshApps,
      }}
    >
      {children}
    </NavigationContext.Provider>
  )
}
