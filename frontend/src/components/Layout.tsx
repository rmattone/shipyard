import { Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'
import { useNavigation } from '@/contexts/NavigationContext'
import { useTheme } from '@/contexts/ThemeContext'
import { Button } from '@/components/ui/button'
import {
  SidebarProvider,
  SidebarInset,
  SidebarTrigger,
} from '@/components/ui/sidebar'
import { AppSidebar } from '@/components/sidebar'
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
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const { currentServer, currentApp, servers, apps, loadingServers, loadingApps } = useNavigation()
  const { theme, setTheme } = useTheme()

  const handleLogout = async () => {
    await logout()
    navigate('/login')
  }

  // Filter apps by current server if selected
  const filteredApps = currentServer
    ? apps.filter(app => app.server_id === currentServer.id)
    : apps

  return (
    <SidebarProvider>
      <AppSidebar />
      <SidebarInset className="flex flex-col max-h-[calc(100vh-2rem)]">
        {/* Top navbar */}
        <header className="border-b border-border/50">
          <div className="flex h-14 items-center justify-between px-4 lg:px-6">
            {/* Breadcrumb navigation */}
            <div className="flex items-center gap-1">
              {/* Sidebar trigger */}
              <SidebarTrigger className="mr-2" />

              {/* Organization (hardcoded for now) */}
              <div className="flex items-center">
                <Button
                  variant="ghost"
                  className="gap-1 px-2 pr-1 dark:text-zinc-300 dark:hover:text-white dark:hover:bg-zinc-800 rounded-r-none"
                  onClick={() => navigate('/')}
                >
                  <div className="h-5 w-5 rounded bg-orange-500 flex items-center justify-center text-xs font-bold text-white">
                    O
                  </div>
                  <span className="hidden sm:inline ml-1">Organization</span>
                </Button>
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button variant="ghost" className="px-1 dark:text-zinc-300 dark:hover:text-white dark:hover:bg-zinc-800 rounded-l-none">
                      <ChevronDown className="h-3 w-3 opacity-50" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="start" className="w-48">
                    <DropdownMenuItem className="gap-2">
                      <div className="h-5 w-5 rounded bg-orange-500 flex items-center justify-center text-xs font-bold text-white">
                        O
                      </div>
                      Organization
                      <span className="text-emerald-500 ml-auto">✓</span>
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem className="gap-2" disabled>
                      <Plus className="h-4 w-4" />
                      Add Organization
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
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
                      {user?.email?.charAt(0).toUpperCase() || 'U'}
                    </div>
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                  {/* User info */}
                  <DropdownMenuLabel className="font-normal">
                    <div className="flex items-center gap-3 py-1">
                      <div className="h-9 w-9 rounded-full bg-emerald-600 flex items-center justify-center text-sm font-semibold text-white flex-shrink-0">
                        {user?.email?.charAt(0).toUpperCase() || 'U'}
                      </div>
                      <div className="flex flex-col space-y-0.5 overflow-hidden">
                        <p className="text-sm font-medium leading-none truncate">
                          {user?.email?.split('@')[0] || 'User'}
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
        </header>

        {/* Main content */}
        <main className="flex-1 min-h-0 overflow-y-auto px-4 lg:px-6 py-6">
          <Outlet />
        </main>

        {/* Footer */}
        <footer className="px-4 lg:px-6 py-4 border-t border-border/50">
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
      </SidebarInset>
    </SidebarProvider>
  )
}
