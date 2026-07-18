import { useState, useEffect } from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import { useNavigation } from '@/contexts/NavigationContext'
import shipyardLogo from '@/assets/shipyard.svg'
import {
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
  SidebarMenuAction,
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

// Single markup for both expanded and icon-collapsed states: labels, the
// group title, chevrons, and app sub-lists degrade via the sidebar's
// group-data-[collapsible=icon] classes, so the width transition stays
// smooth instead of swapping content mid-animation.
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

  const getServerApps = (serverId: number) => {
    return apps.filter(app => app.server_id === serverId)
  }

  return (
    <Sidebar variant="inset" collapsible="icon">
      {/* Header with logo */}
      <SidebarHeader>
        <NavLink
          to="/"
          className="flex items-center gap-2 px-2 py-1 group-data-[collapsible=icon]:px-0 group-data-[collapsible=icon]:justify-center"
        >
          <div className="h-8 w-8 rounded-md bg-slate-800 dark:bg-white p-1.5 flex items-center justify-center flex-shrink-0">
            <img src={shipyardLogo} alt="ShipYard" className="h-full w-full object-contain invert dark:invert-0" />
          </div>
          <span className="font-semibold text-sidebar-foreground group-data-[collapsible=icon]:hidden">
            ShipYard
          </span>
        </NavLink>
      </SidebarHeader>

      <SidebarContent>
        <SidebarGroup>
          <SidebarGroupLabel>Navigation</SidebarGroupLabel>
          <SidebarGroupContent>
            <SidebarMenu>
              {/* Organization overview */}
              <SidebarMenuItem>
                <SidebarMenuButton
                  asChild
                  tooltip="Organization"
                  isActive={!currentServer && !currentApp}
                >
                  <NavLink to="/" end>
                    <BuildingOffice2Icon className="h-4 w-4" />
                    <span>Organization</span>
                  </NavLink>
                </SidebarMenuButton>
              </SidebarMenuItem>

              {/* Servers with their apps */}
              {loadingServers ? (
                <>
                  <SidebarMenuItem><SidebarMenuSkeleton /></SidebarMenuItem>
                  <SidebarMenuItem><SidebarMenuSkeleton /></SidebarMenuItem>
                </>
              ) : (
                servers.map((server) => {
                  const serverApps = getServerApps(server.id)
                  return (
                    <Collapsible
                      key={server.id}
                      asChild
                      open={expandedServers.has(server.id)}
                      onOpenChange={(isOpen) => toggleServer(server.id, isOpen)}
                    >
                      <SidebarMenuItem>
                        <SidebarMenuButton
                          asChild
                          tooltip={server.name}
                          isActive={currentServer?.id === server.id && !currentApp}
                        >
                          <NavLink to={`/servers/${server.id}`}>
                            <div className="h-4 w-4 rounded bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center text-[10px] font-semibold flex-shrink-0">
                              {server.name.charAt(0).toUpperCase()}
                            </div>
                            <span>{server.name}</span>
                          </NavLink>
                        </SidebarMenuButton>
                        <CollapsibleTrigger asChild>
                          <SidebarMenuAction className="data-[state=open]:rotate-90 transition-transform">
                            <ChevronRightIcon className="h-4 w-4" />
                            <span className="sr-only">Toggle apps</span>
                          </SidebarMenuAction>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                          <SidebarMenuSub>
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
                      </SidebarMenuItem>
                    </Collapsible>
                  )
                })
              )}

              {/* Add Server */}
              <SidebarMenuItem>
                <SidebarMenuButton
                  tooltip="Add Server"
                  onClick={() => navigate('/servers/new')}
                  className="text-muted-foreground hover:text-foreground"
                >
                  <PlusIcon className="h-4 w-4" />
                  <span>Add Server</span>
                </SidebarMenuButton>
              </SidebarMenuItem>
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
      </SidebarContent>
    </Sidebar>
  )
}
