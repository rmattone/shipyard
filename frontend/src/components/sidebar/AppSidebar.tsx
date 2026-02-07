import { useState, useEffect } from 'react'
import { NavLink, useNavigate, useLocation } from 'react-router-dom'
import { useNavigation } from '@/contexts/NavigationContext'
import shipyardLogo from '@/assets/shipyard.svg'
import {
  ServerIcon,
  Cog6ToothIcon,
  PlusIcon,
  CubeIcon,
  CircleStackIcon,
  RocketLaunchIcon,
  GlobeAltIcon,
  Squares2X2Icon,
  CommandLineIcon,
  DocumentTextIcon,
  BuildingOffice2Icon,
  ChevronRightIcon,
  WrenchScrewdriverIcon,
} from '@heroicons/react/24/outline'
import {
  Sidebar,
  SidebarContent,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarMenuSkeleton,
  SidebarMenuSub,
  SidebarMenuSubButton,
  SidebarMenuSubItem,
  SidebarSeparator,
} from '@/components/ui/sidebar'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'

export function AppSidebar() {
  const navigate = useNavigate()
  const location = useLocation()
  const { servers, apps, currentServer, currentApp, loadingServers, loadingApps } = useNavigation()

  // Track which servers are expanded
  const [expandedServers, setExpandedServers] = useState<Set<number>>(new Set())

  // Auto-expand current server when it changes
  useEffect(() => {
    if (currentServer) {
      setExpandedServers(prev => {
        const next = new Set(prev)
        next.add(currentServer.id)
        return next
      })
    }
  }, [currentServer?.id])

  // Helper to toggle server expansion
  const toggleServer = (serverId: number, isOpen: boolean) => {
    setExpandedServers(prev => {
      const next = new Set(prev)
      if (isOpen) {
        next.add(serverId)
      } else {
        next.delete(serverId)
      }
      return next
    })
  }

  // Helper to get apps for a specific server
  const getServerApps = (serverId: number) => {
    return apps.filter(app => app.server_id === serverId)
  }

  // Helper to determine contextual URLs and label
  const getContextInfo = () => {
    if (currentApp) {
      return {
        label: `App: ${currentApp.name}`,
        urls: {
          overview: `/apps/${currentApp.id}`,
          deployments: `/apps/${currentApp.id}/deployments`,
          domains: `/apps/${currentApp.id}/domains`,
          logs: `/apps/${currentApp.id}/logs`,
          environment: `/apps/${currentApp.id}/environment`,
          settings: `/apps/${currentApp.id}/settings`,
        },
      }
    }
    if (currentServer) {
      return {
        label: `Server: ${currentServer.name}`,
        urls: {
          overview: `/servers/${currentServer.id}`,
          storage: `/servers/${currentServer.id}/storage`,
          software: `/servers/${currentServer.id}/software`,
          databases: `/servers/${currentServer.id}/databases`,
          settings: `/servers/${currentServer.id}/settings`,
        },
      }
    }
    return {
      label: 'Organization',
      urls: {
        overview: '/',
        settings: '/settings',
      },
    }
  }

  const contextInfo = getContextInfo()

  // Check active states for contextual menu
  const isOverviewActive = currentApp
    ? location.pathname === `/apps/${currentApp.id}`
    : currentServer
      ? location.pathname === `/servers/${currentServer.id}`
      : location.pathname === '/'

  const isSettingsActive = currentApp
    ? location.pathname === `/apps/${currentApp.id}/settings`
    : currentServer
      ? location.pathname === `/servers/${currentServer.id}/settings`
      : location.pathname === '/settings'

  return (
    <Sidebar variant="inset">
      {/* Header with logo */}
      <SidebarHeader>
        <NavLink to="/" className="flex items-center gap-2 px-2 py-1">
          <div className="h-9 w-9 rounded-md bg-slate-800 dark:bg-white p-1.5 flex items-center justify-center">
            <img src={shipyardLogo} alt="ShipYard" className="h-full w-full object-contain invert dark:invert-0" />
          </div>
          <span className="font-semibold text-sidebar-foreground">ShipYard</span>
        </NavLink>
      </SidebarHeader>

      <SidebarContent>
        {/* Navigation Tree Section */}
        <SidebarGroup>
          <SidebarGroupLabel>Navigation</SidebarGroupLabel>
          <SidebarGroupContent>
            <SidebarMenu>
              {/* Organization - always visible, expandable */}
              <Collapsible defaultOpen className="group/collapsible">
                <SidebarMenuItem>
                  <CollapsibleTrigger asChild>
                    <SidebarMenuButton tooltip="Organization">
                      <ChevronRightIcon className="h-4 w-4 transition-transform group-data-[state=open]/collapsible:rotate-90" />
                      <BuildingOffice2Icon className="h-4 w-4" />
                      <span>Organization</span>
                    </SidebarMenuButton>
                  </CollapsibleTrigger>
                  <CollapsibleContent>
                    <SidebarMenuSub className="mr-0 pr-0">
                      {/* Loading state */}
                      {loadingServers ? (
                        <>
                          <SidebarMenuSubItem>
                            <SidebarMenuSkeleton showIcon />
                          </SidebarMenuSubItem>
                          <SidebarMenuSubItem>
                            <SidebarMenuSkeleton showIcon />
                          </SidebarMenuSubItem>
                        </>
                      ) : servers.length === 0 ? (
                        <SidebarMenuSubItem>
                          <span className="text-muted-foreground text-xs pl-2 py-1">
                            No servers
                          </span>
                        </SidebarMenuSubItem>
                      ) : (
                        /* Servers */
                        servers.map((server) => {
                          const serverApps = getServerApps(server.id)
                          const isCurrentServer = currentServer?.id === server.id
                          const isExpanded = expandedServers.has(server.id)

                          return (
                            <Collapsible
                              key={server.id}
                              open={isExpanded}
                              onOpenChange={(open) => toggleServer(server.id, open)}
                              className="group/server"
                            >
                              <SidebarMenuSubItem>
                                <div className="flex items-center">
                                  <CollapsibleTrigger asChild>
                                    <button className="p-1 hover:bg-sidebar-accent rounded">
                                      <ChevronRightIcon className="h-3 w-3 transition-transform group-data-[state=open]/server:rotate-90" />
                                    </button>
                                  </CollapsibleTrigger>
                                  <SidebarMenuSubButton
                                    asChild
                                    isActive={isCurrentServer && !currentApp}
                                    className="flex-1"
                                  >
                                    <NavLink to={`/servers/${server.id}`}>
                                      <div className="h-4 w-4 rounded bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center flex-shrink-0">
                                        <ServerIcon className="h-2.5 w-2.5" />
                                      </div>
                                      <span>{server.name}</span>
                                    </NavLink>
                                  </SidebarMenuSubButton>
                                </div>
                                <CollapsibleContent>
                                  <SidebarMenuSub className="ml-4 mr-0 pl-1.5 pr-0">
                                    {loadingApps ? (
                                      <SidebarMenuSubItem>
                                        <SidebarMenuSkeleton />
                                      </SidebarMenuSubItem>
                                    ) : serverApps.length === 0 ? (
                                      <SidebarMenuSubItem>
                                        <span className="text-muted-foreground text-xs pl-2 py-1">
                                          (no apps)
                                        </span>
                                      </SidebarMenuSubItem>
                                    ) : (
                                      serverApps.map((app) => (
                                        <SidebarMenuSubItem key={app.id}>
                                          <SidebarMenuSubButton
                                            asChild
                                            isActive={currentApp?.id === app.id}
                                          >
                                            <NavLink to={`/apps/${app.id}`}>
                                              <CubeIcon className="h-3 w-3 flex-shrink-0" />
                                              <span className="truncate">{app.name}</span>
                                            </NavLink>
                                          </SidebarMenuSubButton>
                                        </SidebarMenuSubItem>
                                      ))
                                    )}
                                  </SidebarMenuSub>
                                </CollapsibleContent>
                              </SidebarMenuSubItem>
                            </Collapsible>
                          )
                        })
                      )}
                      {/* Add Server */}
                      <SidebarMenuSubItem>
                        <SidebarMenuSubButton
                          onClick={() => navigate('/servers/new')}
                          className="text-muted-foreground hover:text-foreground"
                        >
                          <PlusIcon className="h-3 w-3" />
                          <span>Add Server</span>
                        </SidebarMenuSubButton>
                      </SidebarMenuSubItem>
                    </SidebarMenuSub>
                  </CollapsibleContent>
                </SidebarMenuItem>
              </Collapsible>
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>

        <SidebarSeparator />

        {/* Contextual Menu Section */}
        <SidebarGroup>
          <SidebarGroupLabel>{contextInfo.label}</SidebarGroupLabel>
          <SidebarGroupContent>
            <SidebarMenu>
              {/* Overview - always shown */}
              <SidebarMenuItem>
                <SidebarMenuButton
                  asChild
                  isActive={isOverviewActive}
                  tooltip="Overview"
                >
                  <NavLink to={contextInfo.urls.overview} end>
                    <Squares2X2Icon className="h-4 w-4" />
                    <span>Overview</span>
                  </NavLink>
                </SidebarMenuButton>
              </SidebarMenuItem>

              {/* App-specific items */}
              {currentApp && (
                <>
                  <SidebarMenuItem>
                    <SidebarMenuButton
                      asChild
                      isActive={
                        location.pathname === `/apps/${currentApp.id}/deployments` ||
                        location.pathname.startsWith(`/apps/${currentApp.id}/deployments/`)
                      }
                      tooltip="Deployments"
                    >
                      <NavLink to={contextInfo.urls.deployments!}>
                        <RocketLaunchIcon className="h-4 w-4" />
                        <span>Deployments</span>
                      </NavLink>
                    </SidebarMenuButton>
                  </SidebarMenuItem>
                  <SidebarMenuItem>
                    <SidebarMenuButton
                      asChild
                      isActive={location.pathname === `/apps/${currentApp.id}/domains`}
                      tooltip="Domains"
                    >
                      <NavLink to={contextInfo.urls.domains!}>
                        <GlobeAltIcon className="h-4 w-4" />
                        <span>Domains</span>
                      </NavLink>
                    </SidebarMenuButton>
                  </SidebarMenuItem>
                  <SidebarMenuItem>
                    <SidebarMenuButton
                      asChild
                      isActive={location.pathname === `/apps/${currentApp.id}/logs`}
                      tooltip="Logs"
                    >
                      <NavLink to={contextInfo.urls.logs!}>
                        <DocumentTextIcon className="h-4 w-4" />
                        <span>Logs</span>
                      </NavLink>
                    </SidebarMenuButton>
                  </SidebarMenuItem>
                  <SidebarMenuItem>
                    <SidebarMenuButton
                      asChild
                      isActive={location.pathname === `/apps/${currentApp.id}/environment`}
                      tooltip="Environment"
                    >
                      <NavLink to={contextInfo.urls.environment!}>
                        <CommandLineIcon className="h-4 w-4" />
                        <span>Environment</span>
                      </NavLink>
                    </SidebarMenuButton>
                  </SidebarMenuItem>
                </>
              )}

              {/* Server-specific items (when viewing server, not app) */}
              {currentServer && !currentApp && (
                <>
                  <SidebarMenuItem>
                    <SidebarMenuButton
                      asChild
                      isActive={location.pathname === `/servers/${currentServer.id}/storage`}
                      tooltip="Storage"
                    >
                      <NavLink to={contextInfo.urls.storage!}>
                        <CircleStackIcon className="h-4 w-4" />
                        <span>Storage</span>
                      </NavLink>
                    </SidebarMenuButton>
                  </SidebarMenuItem>
                  <SidebarMenuItem>
                    <SidebarMenuButton
                      asChild
                      isActive={location.pathname === `/servers/${currentServer.id}/software`}
                      tooltip="Software"
                    >
                      <NavLink to={contextInfo.urls.software!}>
                        <WrenchScrewdriverIcon className="h-4 w-4" />
                        <span>Software</span>
                      </NavLink>
                    </SidebarMenuButton>
                  </SidebarMenuItem>
                  <SidebarMenuItem>
                    <SidebarMenuButton
                      asChild
                      isActive={location.pathname.startsWith(`/servers/${currentServer.id}/databases`)}
                      tooltip="Databases"
                    >
                      <NavLink to={contextInfo.urls.databases!}>
                        <CircleStackIcon className="h-4 w-4" />
                        <span>Databases</span>
                      </NavLink>
                    </SidebarMenuButton>
                  </SidebarMenuItem>
                  <SidebarMenuItem>
                    <SidebarMenuButton
                      onClick={() => navigate(`/servers/${currentServer.id}/apps/new`)}
                      tooltip="Add App"
                    >
                      <PlusIcon className="h-4 w-4" />
                      <span>Add App</span>
                    </SidebarMenuButton>
                  </SidebarMenuItem>
                </>
              )}

              {/* Settings - always shown */}
              <SidebarMenuItem>
                <SidebarMenuButton
                  asChild
                  isActive={isSettingsActive}
                  tooltip="Settings"
                >
                  <NavLink to={contextInfo.urls.settings}>
                    <Cog6ToothIcon className="h-4 w-4" />
                    <span>Settings</span>
                  </NavLink>
                </SidebarMenuButton>
              </SidebarMenuItem>
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
      </SidebarContent>
    </Sidebar>
  )
}
