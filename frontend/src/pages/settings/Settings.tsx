import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { gitProvidersApi, GitProvider } from '../../services/api'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { StatusBadge, LoadingSpinner } from '@/components/custom'
import { PlusIcon, TrashIcon } from '@heroicons/react/24/outline'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { cn } from '@/lib/utils'

type SettingsSection = 'source-control' | 'general'

export default function Settings() {
  const navigate = useNavigate()
  const [providers, setProviders] = useState<GitProvider[]>([])
  const [loading, setLoading] = useState(true)
  const [deleteId, setDeleteId] = useState<number | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [activeSection, setActiveSection] = useState<SettingsSection>('source-control')

  useEffect(() => {
    loadProviders()
  }, [])

  const loadProviders = async () => {
    try {
      const response = await gitProvidersApi.list()
      setProviders(response.data)
    } catch {
      toast.error('Failed to load git providers')
    } finally {
      setLoading(false)
    }
  }

  const handleDelete = async () => {
    if (!deleteId) return
    setDeleting(true)
    try {
      await gitProvidersApi.delete(deleteId)
      setProviders(providers.filter(p => p.id !== deleteId))
      toast.success('Git provider deleted')
    } catch {
      toast.error('Failed to delete git provider')
    } finally {
      setDeleting(false)
      setDeleteId(null)
    }
  }

  const getProviderIcon = (type: string) => {
    switch (type) {
      case 'github':
        return '🐙'
      case 'gitlab':
        return '🦊'
      case 'bitbucket':
        return '🪣'
      default:
        return '📦'
    }
  }

  const sidebarItems = [
    { id: 'source-control' as const, label: 'Source Control' },
    { id: 'general' as const, label: 'General' },
  ]

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  return (
    <div className="flex gap-8">
      {/* Sidebar */}
      <div className="w-48 flex-shrink-0">
        <h1 className="text-2xl font-bold mb-6">Settings</h1>
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
      <div className="flex-1 max-w-3xl">
        {activeSection === 'source-control' && (
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle>Source Control</CardTitle>
                  <CardDescription>
                    Connect your Git providers to deploy applications.
                  </CardDescription>
                </div>
                <Button onClick={() => navigate('/settings/git-providers/new')}>
                  <PlusIcon className="h-4 w-4 mr-2" />
                  Add Provider
                </Button>
              </div>
            </CardHeader>
            <CardContent>
              {providers.length === 0 ? (
                <div className="text-center py-8">
                  <p className="text-muted-foreground mb-4">No git providers connected yet.</p>
                  <Button onClick={() => navigate('/settings/git-providers/new')}>
                    <PlusIcon className="h-4 w-4 mr-2" />
                    Connect Your First Provider
                  </Button>
                </div>
              ) : (
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Provider</TableHead>
                      <TableHead>Type</TableHead>
                      <TableHead>Host</TableHead>
                      <TableHead>Applications</TableHead>
                      <TableHead>Default</TableHead>
                      <TableHead className="w-[60px]"></TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {providers.map((provider) => (
                      <TableRow key={provider.id}>
                        <TableCell>
                          <Link
                            to={`/settings/git-providers/${provider.id}`}
                            className="font-medium text-primary hover:underline flex items-center gap-2"
                          >
                            <span>{getProviderIcon(provider.type)}</span>
                            {provider.name}
                          </Link>
                        </TableCell>
                        <TableCell className="capitalize">{provider.type}</TableCell>
                        <TableCell className="text-muted-foreground">
                          {provider.host || '-'}
                        </TableCell>
                        <TableCell>{provider.applications_count || 0}</TableCell>
                        <TableCell>
                          {provider.is_default && (
                            <StatusBadge status="active" />
                          )}
                        </TableCell>
                        <TableCell>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setDeleteId(provider.id)}
                            disabled={(provider.applications_count || 0) > 0}
                          >
                            <TrashIcon className="h-4 w-4 text-destructive" />
                          </Button>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              )}
            </CardContent>
          </Card>
        )}

        {activeSection === 'general' && (
          <Card>
            <CardHeader>
              <CardTitle>General</CardTitle>
              <CardDescription>
                Organization settings and preferences.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="space-y-6">
                <div className="flex items-start justify-between py-4 border-b">
                  <div>
                    <p className="font-medium">Organization name</p>
                    <p className="text-sm text-muted-foreground">
                      The name of your organization.
                    </p>
                  </div>
                  <div className="text-sm text-muted-foreground">
                    Coming soon...
                  </div>
                </div>

                <div className="flex items-start justify-between py-4">
                  <div>
                    <p className="font-medium">Organization avatar</p>
                    <p className="text-sm text-muted-foreground">
                      Your organization's profile picture.
                    </p>
                  </div>
                  <div className="text-sm text-muted-foreground">
                    Coming soon...
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>
        )}
      </div>

      {/* Delete Confirmation Dialog */}
      <AlertDialog open={deleteId !== null} onOpenChange={() => setDeleteId(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Git Provider</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete this git provider? This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDelete}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              disabled={deleting}
            >
              {deleting ? 'Deleting...' : 'Delete'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
