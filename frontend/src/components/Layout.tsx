import { useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { useAuth } from '../hooks/useAuth'
import { useNavigation } from '@/contexts/NavigationContext'
import { useTheme } from '@/contexts/ThemeContext'
import { getErrorMessage } from '@/services/api'
import { Button } from '@/components/ui/button'
import shipyardLogo from '@/assets/shipyard.svg'
import { ContextTabs } from '@/components/ContextTabs'
import { CreateOrganizationDialog } from '@/components/CreateOrganizationDialog'
import {
  ServerIcon,
  ArrowRightStartOnRectangleIcon,
  ComputerDesktopIcon,
  SunIcon,
  MoonIcon,
} from '@heroicons/react/24/outline'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
  DropdownMenuLabel,
} from '@/components/ui/dropdown-menu'
import { ChevronDown, Plus } from 'lucide-react'
import { cn } from '@/lib/utils'

export default function Layout() {
  const { user, logout, organizations, currentOrganization, switchOrganization, loading } = useAuth()
  const navigate = useNavigate()
  const { currentServer, currentApp, servers, apps, loadingServers, loadingApps } = useNavigation()
  const { theme, setTheme } = useTheme()
  const [createOrgOpen, setCreateOrgOpen] = useState(false)
  const [switchingOrg, setSwitchingOrg] = useState(false)

  const handleLogout = async () => {
    await logout()
    navigate('/login')
  }

  const handleSwitchOrganization = async (id: number) => {
    if (id === currentOrganization?.id) return
    setSwitchingOrg(true)
    try {
      await switchOrganization(id)
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to switch organization'))
      setSwitchingOrg(false)
    }
  }

  // Zero organizations (removed from the last one): everything org-scoped
  // would 403, so force creating an organization before showing the app.
  if (!loading && user && organizations.length === 0) {
    return (
      <div className="flex h-screen w-full flex-col items-center justify-center gap-4 px-6 text-center">
        <div className="h-12 w-12 rounded-md bg-slate-800 dark:bg-white p-2.5">
          <img src={shipyardLogo} alt="ShipYard" className="h-full w-full object-contain invert dark:invert-0" />
        </div>
        <h1 className="text-xl font-semibold">Create your first organization</h1>
        <p className="max-w-md text-sm text-muted-foreground">
          You don't belong to any organization yet. Create one to start managing
          servers and applications, or ask a teammate for an invitation.
        </p>
        <Button onClick={() => setCreateOrgOpen(true)}>Create organization</Button>
        <Button variant="ghost" onClick={handleLogout}>Sign out</Button>
        <CreateOrganizationDialog open={createOrgOpen} onOpenChange={setCreateOrgOpen} />
      </div>
    )
  }

  // Filter apps by current server if selected
  const filteredApps = currentServer
    ? apps.filter(app => app.server_id === currentServer.id)
    : apps

  return (
    <div className="flex h-screen w-full flex-col">
      {/* Top navbar */}
      <header className="border-b border-border/50 bg-zinc-50 dark:bg-black/20">
        <div className="flex h-[5.5rem] items-center justify-between px-6 lg:px-8">
          {/* Breadcrumb navigation */}
          <div className="flex items-center gap-1">
            {/* Logo */}
            <NavLink to="/" className="flex items-center gap-2 mr-5">
              <div className="h-8 w-8 rounded-md bg-slate-800 dark:bg-white p-1.5 flex items-center justify-center flex-shrink-0">
                <img src={shipyardLogo} alt="ShipYard" className="h-full w-full object-contain invert dark:invert-0" />
              </div>
              <span className="font-semibold hidden sm:inline">ShipYard</span>
            </NavLink>

            {/* Organization switcher */}
            <div className="flex items-center">
              <Button
                variant="ghost"
                className="gap-1 px-2 pr-1 dark:text-zinc-300 dark:hover:text-white dark:hover:bg-zinc-800 rounded-r-none"
                onClick={() => navigate('/')}
              >
                <div className="h-5 w-5 rounded bg-orange-500 flex items-center justify-center text-xs font-bold text-white">
                  {(currentOrganization?.name ?? 'O').charAt(0).toUpperCase()}
                </div>
                <span className="hidden sm:inline ml-1">{currentOrganization?.name ?? 'Organization'}</span>
              </Button>
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" className="px-1 dark:text-zinc-300 dark:hover:text-white dark:hover:bg-zinc-800 rounded-l-none">
                    <ChevronDown className="h-3 w-3 opacity-50" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="w-56">
                  {organizations.map((organization) => (
                    <DropdownMenuItem
                      key={organization.id}
                      className="gap-2"
                      disabled={switchingOrg}
                      onClick={() => handleSwitchOrganization(organization.id)}
                    >
                      <div className="h-5 w-5 rounded bg-orange-500 flex items-center justify-center text-xs font-bold text-white">
                        {organization.name.charAt(0).toUpperCase()}
                      </div>
                      <span className="flex-1 truncate">{organization.name}</span>
                      {organization.id === currentOrganization?.id && (
                        <span className="text-emerald-500 ml-auto">✓</span>
                      )}
                    </DropdownMenuItem>
                  ))}
                  <DropdownMenuSeparator />
                  <DropdownMenuItem className="gap-2" onClick={() => setCreateOrgOpen(true)}>
                    <Plus className="h-4 w-4" />
                    Add Organization
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
              <CreateOrganizationDialog open={createOrgOpen} onOpenChange={setCreateOrgOpen} />
            </div>

            {/* Server selector (only show if on a server or app page) */}
            {(currentServer || currentApp) && (
              <>
                <span className="text-muted-foreground dark:text-zinc-500 mx-1">/</span>
                <div className="flex items-center">
                  <Button
                    variant="ghost"
                    className="gap-1 px-2 pr-1 dark:text-zinc-300 dark:hover:text-white dark:hover:bg-zinc-800 rounded-r-none"
                    onClick={() => currentServer && navigate(`/servers/${currentServer.id}`)}
                  >
                    <div className="h-5 w-5 rounded bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center">
                      <ServerIcon className="h-3 w-3" />
                    </div>
                    <span className="hidden sm:inline ml-1">{currentServer?.name}</span>
                  </Button>
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <Button variant="ghost" className="px-1 dark:text-zinc-300 dark:hover:text-white dark:hover:bg-zinc-800 rounded-l-none">
                        <ChevronDown className="h-3 w-3 opacity-50" />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="w-56">
                      {loadingServers ? (
                        <DropdownMenuItem disabled>Loading...</DropdownMenuItem>
                      ) : servers.length === 0 ? (
                        <DropdownMenuItem disabled>No servers</DropdownMenuItem>
                      ) : (
                        servers.map(server => (
                          <DropdownMenuItem
                            key={server.id}
                            onClick={() => navigate(`/servers/${server.id}`)}
                            className="gap-2"
                          >
                            <div className="h-5 w-5 rounded bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center">
                              <ServerIcon className="h-3 w-3" />
                            </div>
                            <span className="flex-1 truncate">{server.name}</span>
                            {server.id === currentServer?.id && (
                              <span className="text-emerald-500">✓</span>
                            )}
                          </DropdownMenuItem>
                        ))
                      )}
                      <DropdownMenuSeparator />
                      <DropdownMenuItem onClick={() => navigate('/servers/new')} className="gap-2">
                        <Plus className="h-4 w-4" />
                        Add Server
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </div>
              </>
            )}

            {/* Application selector (only show if on an app page) */}
            {currentApp && currentServer && (
              <>
                <span className="text-muted-foreground dark:text-zinc-500 mx-1">/</span>
                <div className="flex items-center">
                  <Button
                    variant="ghost"
                    className="gap-1 px-2 pr-1 dark:text-zinc-300 dark:hover:text-white dark:hover:bg-zinc-800 rounded-r-none"
                    onClick={() => navigate(`/apps/${currentApp.id}`)}
                  >
                    <div className="h-5 w-5 rounded bg-emerald-600 flex items-center justify-center text-xs font-bold text-white">
                      {currentApp.name.charAt(0).toUpperCase()}
                    </div>
                    <span className="hidden sm:inline ml-1">{currentApp.name}</span>
                  </Button>
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <Button variant="ghost" className="px-1 dark:text-zinc-300 dark:hover:text-white dark:hover:bg-zinc-800 rounded-l-none">
                        <ChevronDown className="h-3 w-3 opacity-50" />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="w-64">
                      {loadingApps ? (
                        <DropdownMenuItem disabled>Loading...</DropdownMenuItem>
                      ) : filteredApps.length === 0 ? (
                        <DropdownMenuItem disabled>No applications</DropdownMenuItem>
                      ) : (
                        filteredApps.map(app => (
                          <DropdownMenuItem
                            key={app.id}
                            onClick={() => navigate(`/apps/${app.id}`)}
                            className="gap-2"
                          >
                            <div className="h-5 w-5 rounded bg-emerald-600 flex items-center justify-center text-xs font-bold text-white">
                              {app.name.charAt(0).toUpperCase()}
                            </div>
                            <span className="flex-1 truncate">{app.name}</span>
                            {app.id === currentApp.id && (
                              <span className="text-emerald-500">✓</span>
                            )}
                          </DropdownMenuItem>
                        ))
                      )}
                      <DropdownMenuSeparator />
                      <DropdownMenuItem onClick={() => navigate(`/servers/${currentServer.id}/apps/new`)} className="gap-2">
                        <Plus className="h-4 w-4" />
                        Add Application
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </div>
              </>
            )}
          </div>

          {/* Right side - User menu */}
          <div className="flex items-center gap-2">
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="h-8 w-8 rounded-full p-0 dark:hover:bg-zinc-800">
                  <div className="h-8 w-8 rounded-full bg-emerald-600 flex items-center justify-center text-sm font-semibold text-white">
                    {(user?.name || user?.email)?.charAt(0).toUpperCase() || 'U'}
                  </div>
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-56">
                {/* User info */}
                <DropdownMenuLabel className="font-normal">
                  <div className="flex items-center gap-3 py-1">
                    <div className="h-9 w-9 rounded-full bg-emerald-600 flex items-center justify-center text-sm font-semibold text-white flex-shrink-0">
                      {(user?.name || user?.email)?.charAt(0).toUpperCase() || 'U'}
                    </div>
                    <div className="flex flex-col space-y-0.5 overflow-hidden">
                      <p className="text-sm font-medium leading-none truncate">
                        {user?.name || user?.email?.split('@')[0] || 'User'}
                      </p>
                      <p className="text-xs text-muted-foreground truncate">
                        {user?.email}
                      </p>
                    </div>
                  </div>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />

                {/* Sign out */}
                <DropdownMenuItem onClick={handleLogout} className="cursor-pointer gap-2">
                  <ArrowRightStartOnRectangleIcon className="h-4 w-4" />
                  Sign out
                </DropdownMenuItem>
                <DropdownMenuSeparator />

                {/* Theme selector */}
                <div className="px-2 py-1.5">
                  <div className="flex items-center justify-between">
                    <span className="text-sm">Theme</span>
                    <div className="flex items-center gap-1 bg-muted rounded-md p-0.5">
                      <button
                        onClick={() => setTheme('system')}
                        className={cn(
                          'p-1.5 rounded transition-colors',
                          theme === 'system' ? 'bg-background shadow-sm' : 'hover:bg-background/50'
                        )}
                        title="System"
                      >
                        <ComputerDesktopIcon className="h-4 w-4" />
                      </button>
                      <button
                        onClick={() => setTheme('light')}
                        className={cn(
                          'p-1.5 rounded transition-colors',
                          theme === 'light' ? 'bg-background shadow-sm' : 'hover:bg-background/50'
                        )}
                        title="Light"
                      >
                        <SunIcon className="h-4 w-4" />
                      </button>
                      <button
                        onClick={() => setTheme('dark')}
                        className={cn(
                          'p-1.5 rounded transition-colors',
                          theme === 'dark' ? 'bg-background shadow-sm' : 'hover:bg-background/50'
                        )}
                        title="Dark"
                      >
                        <MoonIcon className="h-4 w-4" />
                      </button>
                    </div>
                  </div>
                </div>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </div>

        {/* Context tabs (org / server / app views) */}
        <ContextTabs />
      </header>

      {/* Main content */}
      <main className="flex-1 min-h-0 overflow-y-auto">
        <div className="mx-auto w-full max-w-6xl px-6 lg:px-10 py-8 lg:py-12">
          <Outlet />
        </div>
      </main>

      {/* Footer */}
      <footer className="px-6 lg:px-8 py-4 border-t border-border/50">
        <div className="flex items-center justify-between text-sm text-muted-foreground dark:text-zinc-500">
          <div>
            ShipYard © {new Date().getFullYear()}
          </div>
          <div className="flex items-center gap-4">
            <a href="#" className="hover:text-foreground dark:hover:text-zinc-300 transition-colors">Status</a>
            <a href="#" className="hover:text-foreground dark:hover:text-zinc-300 transition-colors">Docs</a>
            <a href="#" className="hover:text-foreground dark:hover:text-zinc-300 transition-colors">Help</a>
          </div>
        </div>
      </footer>
    </div>
  )
}
