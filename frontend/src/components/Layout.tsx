import { useState, useRef, useEffect } from 'react'
import { NavLink, Outlet, useNavigate, useLocation } from 'react-router-dom'
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
import { ChevronDown, Plus, Menu, Check } from 'lucide-react'
import { Sheet, SheetContent, SheetTitle, SheetDescription, SheetTrigger } from '@/components/ui/sheet'
import { cn } from '@/lib/utils'

export default function Layout() {
  const { user, logout, organizations, currentOrganization, switchOrganization, loading } = useAuth()
  const navigate = useNavigate()
  const { pathname } = useLocation()
  const [navigationOpen, setNavigationOpen] = useState(false)
  const [scrolled, setScrolled] = useState(false)
  const mainRef = useRef<HTMLElement>(null)
  const wideContent = /\/(terminal|logs)$/.test(pathname) || /\/deployments\/\d+$/.test(pathname)
  const { currentServer, currentApp, servers, apps, loadingServers, loadingApps } = useNavigation()
  const { theme, setTheme } = useTheme()
  const [createOrgOpen, setCreateOrgOpen] = useState(false)
  const [switchingOrg, setSwitchingOrg] = useState(false)

  // The header only grows an edge once content is actually underneath it.
  useEffect(() => {
    const main = mainRef.current
    if (!main) return
    const update = () => setScrolled(main.scrollTop > 2)
    update()
    main.addEventListener('scroll', update, { passive: true })
    return () => main.removeEventListener('scroll', update)
  }, [])

  // New destination: start reading from the top, not wherever the last page left off.
  useEffect(() => {
    mainRef.current?.scrollTo({ top: 0 })
  }, [pathname])

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
    <div className="flex h-dvh w-full flex-col overflow-hidden">
      <a href="#main-content" className="skip-link">Skip to content</a>
      {/* Top navbar */}
      <header className="glass header-edge relative z-30 shrink-0 bg-card/85 backdrop-blur-xl" data-scrolled={scrolled}>
        <div className="flex h-14 items-center justify-between gap-2 px-3 sm:h-16 sm:px-6">
          <Sheet open={navigationOpen} onOpenChange={setNavigationOpen}>
            <SheetTrigger asChild><Button variant="ghost" size="icon" className="shrink-0 lg:hidden" aria-label="Open navigation"><Menu /></Button></SheetTrigger>
            <SheetContent side="left" className="w-72 overflow-y-auto p-0 pt-6">
              <SheetTitle className="px-6">ShipYard</SheetTitle>
              <SheetDescription className="px-6 pb-2">Navigate your workspace</SheetDescription>
              <ContextTabs onNavigate={() => setNavigationOpen(false)} />
            </SheetContent>
          </Sheet>
          {/* Breadcrumb navigation */}
          <div className="flex min-w-0 flex-1 items-center gap-1 overflow-x-auto">
            {/* Logo */}
            <NavLink to="/" aria-label="ShipYard home" className="group mr-2 flex shrink-0 items-center gap-2 rounded-md sm:mr-5">
              <div className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-md bg-foreground p-1.5 transition-transform duration-150 ease-out group-hover:scale-105">
                <img src={shipyardLogo} alt="" className="h-full w-full object-contain invert dark:invert-0" />
              </div>
              <span className="hidden font-semibold tracking-tight sm:inline">ShipYard</span>
            </NavLink>

            {/* Organization switcher */}
            <div className="flex items-center">
              <Button
                variant="ghost"
                className="gap-1 rounded-r-none px-2 pr-1"
                onClick={() => navigate('/')}
              >
                <div className="h-5 w-5 rounded bg-orange-500 flex items-center justify-center text-xs font-bold text-white">
                  {(currentOrganization?.name ?? 'O').charAt(0).toUpperCase()}
                </div>
                <span className="hidden sm:inline ml-1">{currentOrganization?.name ?? 'Organization'}</span>
              </Button>
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" aria-label="Switch organization" className="rounded-l-none px-1">
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
                        <Check className="ml-auto h-4 w-4 text-primary" aria-label="Current" />
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
                <span className="mx-1 select-none text-muted-foreground/60">/</span>
                <div className="flex items-center">
                  <Button
                    variant="ghost"
                    className="gap-1 rounded-r-none px-2 pr-1"
                    onClick={() => currentServer && navigate(`/servers/${currentServer.id}`)}
                  >
                    <div className="flex h-5 w-5 items-center justify-center rounded bg-muted text-foreground">
                      <ServerIcon className="h-3 w-3" />
                    </div>
                    <span className="hidden sm:inline ml-1">{currentServer?.name}</span>
                  </Button>
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <Button variant="ghost" aria-label="Switch server" className="rounded-l-none px-1">
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
                            <div className="flex h-5 w-5 items-center justify-center rounded bg-muted text-foreground">
                              <ServerIcon className="h-3 w-3" />
                            </div>
                            <span className="flex-1 truncate">{server.name}</span>
                            {server.id === currentServer?.id && (
                              <Check className="h-4 w-4 text-primary" aria-label="Current" />
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
                <span className="mx-1 select-none text-muted-foreground/60">/</span>
                <div className="flex items-center">
                  <Button
                    variant="ghost"
                    className="gap-1 rounded-r-none px-2 pr-1"
                    onClick={() => navigate(`/apps/${currentApp.id}`)}
                  >
                    <div className="h-5 w-5 rounded bg-emerald-600 flex items-center justify-center text-xs font-bold text-white">
                      {currentApp.name.charAt(0).toUpperCase()}
                    </div>
                    <span className="hidden sm:inline ml-1">{currentApp.name}</span>
                  </Button>
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <Button variant="ghost" aria-label="Switch application" className="rounded-l-none px-1">
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
                              <Check className="h-4 w-4 text-primary" aria-label="Current" />
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
                <Button variant="ghost" size="icon" aria-label="User menu" className="h-8 w-8 rounded-full p-0">
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
                          'pressable rounded p-1.5',
                          theme === 'system' ? 'bg-background shadow-sm' : 'hover:bg-background/50'
                        )}
                        title="System"
                      >
                        <ComputerDesktopIcon className="h-4 w-4" />
                      </button>
                      <button
                        onClick={() => setTheme('light')}
                        className={cn(
                          'pressable rounded p-1.5',
                          theme === 'light' ? 'bg-background shadow-sm' : 'hover:bg-background/50'
                        )}
                        title="Light"
                      >
                        <SunIcon className="h-4 w-4" />
                      </button>
                      <button
                        onClick={() => setTheme('dark')}
                        className={cn(
                          'pressable rounded p-1.5',
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

      <div className="flex min-h-0 flex-1">
        <aside className="hidden w-[208px] shrink-0 overflow-y-auto border-r border-border/60 bg-background lg:block">
          <ContextTabs />
        </aside>
      <main id="main-content" ref={mainRef} tabIndex={-1} className="min-w-0 flex-1 overflow-y-auto outline-none">
        <div key={pathname} className={cn('page-enter mx-auto w-full px-4 py-6 sm:px-6 lg:px-8 lg:py-8', wideContent ? 'max-w-none' : 'max-w-[1440px]')}>
          <Outlet />
        </div>
      </main>
      </div>
    </div>
  )
}
