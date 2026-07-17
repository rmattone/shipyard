import { useState, useEffect } from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import { useNavigation } from '@/contexts/NavigationContext'
import shipyardLogo from '@/assets/shipyard.svg'
import {
  ServerIcon,
  PlusIcon,
  CubeIcon,
  BuildingOffice2Icon,
  ChevronRightIcon,
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
} from '@/components/ui/sidebar'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'

export function AppSidebar() {
  const navigate = useNavigate()
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

      </SidebarContent>
    </Sidebar>
  )
}
