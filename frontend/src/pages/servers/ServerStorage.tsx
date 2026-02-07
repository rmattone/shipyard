import { useState } from 'react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { cn } from '@/lib/utils'

type StorageSection = 'databases' | 'backups'

export default function ServerStorage() {
  const [activeSection, setActiveSection] = useState<StorageSection>('databases')

  const sidebarItems = [
    { id: 'databases' as const, label: 'Databases' },
    { id: 'backups' as const, label: 'Backups' },
  ]

  return (
    <div className="flex gap-8">
      {/* Sidebar */}
      <div className="w-48 flex-shrink-0">
        <h1 className="text-2xl font-bold mb-6">Storage</h1>
        <nav className="space-y-1">
          {sidebarItems.map((item) => (
            <button
              key={item.id}
              onClick={() => setActiveSection(item.id)}
              className={cn(
                'w-full text-left px-3 py-2 rounded-md text-sm font-medium transition-colors',
                activeSection === item.id
                  ? 'bg-muted text-foreground'
                  : 'text-muted-foreground hover:text-foreground hover:bg-muted/50'
              )}
            >
              {item.label}
            </button>
          ))}
        </nav>
      </div>

      {/* Content */}
      <div className="flex-1 max-w-2xl">
        {activeSection === 'databases' && (
          <Card>
            <CardHeader>
              <CardTitle>Databases</CardTitle>
              <CardDescription>
                Create and manage MySQL, PostgreSQL, and other databases on this server.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="text-center py-8 text-muted-foreground">
                Coming soon...
              </div>
            </CardContent>
          </Card>
        )}

        {activeSection === 'backups' && (
          <Card>
            <CardHeader>
              <CardTitle>Backups</CardTitle>
              <CardDescription>
                Schedule and manage database backups for this server.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="text-center py-8 text-muted-foreground">
                Coming soon...
              </div>
            </CardContent>
          </Card>
        )}
      </div>
    </div>
  )
}
