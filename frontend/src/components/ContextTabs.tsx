import { NavLink } from 'react-router-dom'
import { useNavigation } from '@/contexts/NavigationContext'
import { cn } from '@/lib/utils'
import { ArrowLeft, LayoutDashboard, Server, Settings, Rocket, Globe, ScrollText, Clock, Activity, Terminal, Database, Package } from 'lucide-react'

const icons = { Overview: LayoutDashboard, Servers: Server, Settings, Deployments: Rocket, 'Domains & TLS': Globe, Logs: ScrollText, 'Scheduled tasks': Clock, Processes: Activity, Terminal, Databases: Database, Software: Package }

interface TabItem {
  label: string
  to: string
  end?: boolean
}

export function ContextTabs({ onNavigate }: { onNavigate?: () => void }) {
  const { currentServer, currentApp } = useNavigation()

  let tabs: TabItem[]
  if (currentApp) {
    tabs = [
      { label: 'Overview', to: `/apps/${currentApp.id}`, end: true },
      { label: 'Deployments', to: `/apps/${currentApp.id}/deployments` },
      { label: 'Domains & TLS', to: `/apps/${currentApp.id}/domains` },
      { label: 'Logs', to: `/apps/${currentApp.id}/logs` },
      { label: 'Scheduled tasks', to: `/apps/${currentApp.id}/scheduler` },
      { label: 'Processes', to: `/apps/${currentApp.id}/daemons` },
      { label: 'Settings', to: `/apps/${currentApp.id}/settings` },
    ]
  } else if (currentServer) {
    tabs = [
      { label: 'Overview', to: `/servers/${currentServer.id}`, end: true },
      { label: 'Software', to: `/servers/${currentServer.id}/software` },
      { label: 'Databases', to: `/servers/${currentServer.id}/databases` },
      { label: 'Scheduled tasks', to: `/servers/${currentServer.id}/scheduler` },
      { label: 'Processes', to: `/servers/${currentServer.id}/daemons` },
      { label: 'Terminal', to: `/servers/${currentServer.id}/terminal` },
      { label: 'Settings', to: `/servers/${currentServer.id}/settings` },
    ]
  } else {
    tabs = [
      { label: 'Servers', to: '/', end: true },
      { label: 'Settings', to: '/settings' },
    ]
  }

  return (
    <nav aria-label="Resource navigation" className="flex flex-col px-3 py-6">
      {(currentServer || currentApp) && (
        <NavLink onClick={onNavigate} to={currentApp && currentServer ? `/servers/${currentServer.id}` : '/'} className="pressable group mb-5 flex items-center gap-2 rounded-md px-3 py-2 text-xs text-muted-foreground hover:bg-card hover:text-foreground">
          <ArrowLeft className="h-3.5 w-3.5 transition-transform duration-150 ease-out group-hover:-translate-x-0.5" />
          {currentApp ? 'Back to server' : 'All servers'}
        </NavLink>
      )}
      <div className="px-3 pb-3 text-[10px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
        {currentApp ? 'Application' : currentServer ? 'Server' : 'Organization'}
      </div>
      {(currentApp || currentServer) && (
        <div className="mb-4 truncate px-3 text-sm font-semibold tracking-tight" title={currentApp?.name ?? currentServer?.name}>
          {currentApp?.name ?? currentServer?.name}
        </div>
      )}
      {tabs.map((tab) => {
        const Icon = icons[tab.label as keyof typeof icons] ?? LayoutDashboard
        return (
        <div key={tab.to} className={tab.label === 'Settings' ? 'mt-5 border-t border-border/70 pt-3' : 'mb-1'}>
        <NavLink
          to={tab.to}
          end={tab.end}
          onClick={onNavigate}
          className={({ isActive }) =>
            cn(
              'pressable group relative flex min-h-10 items-center gap-2.5 rounded-md px-3 py-2 text-[13px] before:absolute before:left-0 before:top-3 before:bottom-3 before:w-0.5 before:rounded-full before:transition-colors',
              isActive
                ? 'bg-card text-foreground font-semibold shadow-sm ring-1 ring-border/60 before:bg-primary [&>svg]:text-primary'
                : 'text-muted-foreground before:bg-transparent hover:bg-card/70 hover:text-foreground'
            )
          }
        >
          <Icon strokeWidth={1.6} className="h-4 w-4 shrink-0 transition-colors duration-150" />{tab.label}
        </NavLink>
        </div>
      )})}
    </nav>
  )
}
