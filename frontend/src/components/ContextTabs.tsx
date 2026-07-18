import { NavLink } from 'react-router-dom'
import { useNavigation } from '@/contexts/NavigationContext'
import { cn } from '@/lib/utils'

interface TabItem {
  label: string
  to: string
  end?: boolean
}

export function ContextTabs() {
  const { currentServer, currentApp } = useNavigation()

  let tabs: TabItem[]
  if (currentApp) {
    tabs = [
      { label: 'Overview', to: `/apps/${currentApp.id}`, end: true },
      { label: 'Deployments', to: `/apps/${currentApp.id}/deployments` },
      { label: 'Domains', to: `/apps/${currentApp.id}/domains` },
      { label: 'Logs', to: `/apps/${currentApp.id}/logs` },
      { label: 'Scheduler', to: `/apps/${currentApp.id}/scheduler` },
      { label: 'Settings', to: `/apps/${currentApp.id}/settings` },
    ]
  } else if (currentServer) {
    tabs = [
      { label: 'Overview', to: `/servers/${currentServer.id}`, end: true },
      { label: 'Software', to: `/servers/${currentServer.id}/software` },
      { label: 'Databases', to: `/servers/${currentServer.id}/databases` },
      { label: 'Scheduler', to: `/servers/${currentServer.id}/scheduler` },
      { label: 'Settings', to: `/servers/${currentServer.id}/settings` },
    ]
  } else {
    tabs = [
      { label: 'Overview', to: '/', end: true },
      { label: 'Settings', to: '/settings' },
    ]
  }

  return (
    <nav className="flex items-center gap-6 px-4 lg:px-6 overflow-x-auto">
      {tabs.map((tab) => (
        <NavLink
          key={tab.to}
          to={tab.to}
          end={tab.end}
          className={({ isActive }) =>
            cn(
              'whitespace-nowrap border-b-2 pb-2.5 text-sm transition-colors',
              isActive
                ? 'border-foreground text-foreground font-medium'
                : 'border-transparent text-muted-foreground hover:text-foreground'
            )
          }
        >
          {tab.label}
        </NavLink>
      ))}
    </nav>
  )
}
