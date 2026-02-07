import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { applicationsApi, domainsApi, Domain, Application } from '../../services/api'
import { toast } from 'sonner'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { LoadingSpinner } from '@/components/custom'
import { NginxConfigModal } from '@/components/NginxConfigModal'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  GlobeAltIcon,
  LockClosedIcon,
  LockOpenIcon,
  PlusIcon,
  EllipsisVerticalIcon,
  TrashIcon,
  StarIcon,
  ShieldCheckIcon,
  Cog6ToothIcon,
} from '@heroicons/react/24/outline'

export default function AppDomains() {
  const { id } = useParams<{ id: string }>()
  const [app, setApp] = useState<Application | null>(null)
  const [domains, setDomains] = useState<Domain[]>([])
  const [loading, setLoading] = useState(true)
  const [newDomain, setNewDomain] = useState('')
  const [adding, setAdding] = useState(false)
  const [nginxModalOpen, setNginxModalOpen] = useState(false)
  const [sslModalOpen, setSslModalOpen] = useState(false)
  const [sslEmail, setSslEmail] = useState('')
  const [selectedDomain, setSelectedDomain] = useState<Domain | null>(null)
  const [requestingSsl, setRequestingSsl] = useState(false)

  useEffect(() => {
    if (!id) return
    loadData()
  }, [id])

  const loadData = async () => {
    try {
      const [appResponse, domainsResponse] = await Promise.all([
        applicationsApi.get(parseInt(id!)),
        domainsApi.list(parseInt(id!)),
      ])
      setApp(appResponse.data)
      setDomains(domainsResponse.data)
    } catch {
      toast.error('Failed to load application data')
    } finally {
      setLoading(false)
    }
  }

  const handleAddDomain = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!newDomain.trim()) return

    setAdding(true)
    try {
      const response = await domainsApi.create(parseInt(id!), newDomain.trim())
      setDomains([...domains, response.data])
      setNewDomain('')
      toast.success('Domain added successfully')
    } catch (error: unknown) {
      const axiosError = error as { response?: { data?: { message?: string } } }
      toast.error(axiosError.response?.data?.message || 'Failed to add domain')
    } finally {
      setAdding(false)
    }
  }

  const handleDeleteDomain = async (domain: Domain) => {
    if (!confirm(`Are you sure you want to remove ${domain.domain}?`)) return

    try {
      await domainsApi.delete(parseInt(id!), domain.id)
      setDomains(domains.filter((d) => d.id !== domain.id))
      toast.success('Domain removed successfully')
    } catch (error: unknown) {
      const axiosError = error as { response?: { data?: { message?: string } } }
      toast.error(axiosError.response?.data?.message || 'Failed to remove domain')
    }
  }

  const handleSetPrimary = async (domain: Domain) => {
    try {
      await domainsApi.setPrimary(parseInt(id!), domain.id)
      // Reload domains to get updated primary status
      const response = await domainsApi.list(parseInt(id!))
      setDomains(response.data)
      toast.success(`${domain.domain} is now the primary domain`)
    } catch (error: unknown) {
      const axiosError = error as { response?: { data?: { message?: string } } }
      toast.error(axiosError.response?.data?.message || 'Failed to set primary domain')
    }
  }

  const openSslModal = (domain: Domain) => {
    setSelectedDomain(domain)
    setSslEmail('')
    setSslModalOpen(true)
  }

  const handleRequestSsl = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedDomain || !sslEmail.trim()) return

    setRequestingSsl(true)
    try {
      const response = await domainsApi.requestSsl(parseInt(id!), selectedDomain.id, sslEmail.trim())
      // Update the domain in the list
      setDomains(domains.map((d) => (d.id === selectedDomain.id ? response.data.domain : d)))
      setSslModalOpen(false)
      toast.success('SSL certificate obtained successfully')
    } catch (error: unknown) {
      const axiosError = error as { response?: { data?: { message?: string } } }
      toast.error(axiosError.response?.data?.message || 'Failed to obtain SSL certificate')
    } finally {
      setRequestingSsl(false)
    }
  }

  const formatExpiryDate = (date: string | null) => {
    if (!date) return null
    const d = new Date(date)
    const now = new Date()
    const diffTime = d.getTime() - now.getTime()
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24))
    return `${diffDays} days`
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  if (!app) return null

  const primaryDomain = domains.find((d) => d.is_primary)
  const aliasDomains = domains.filter((d) => !d.is_primary)
  const sslEnabledDomains = domains.filter((d) => d.ssl_enabled)

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">Domains</h1>
          <p className="text-muted-foreground">Manage domains pointing to this application</p>
        </div>
        <Button variant="outline" onClick={() => setNginxModalOpen(true)}>
          <Cog6ToothIcon className="h-4 w-4 mr-2" />
          Edit Nginx Configuration
        </Button>
      </div>

      {/* Primary Domain */}
      <Card>
        <CardHeader>
          <CardTitle>Primary Domain</CardTitle>
          <CardDescription>The main domain for this application</CardDescription>
        </CardHeader>
        <CardContent>
          {primaryDomain ? (
            <DomainRow
              domain={primaryDomain}
              onDelete={handleDeleteDomain}
              onSetPrimary={handleSetPrimary}
              onRequestSsl={openSslModal}
              showPrimaryAction={false}
              canDelete={domains.length > 1}
            />
          ) : (
            <p className="text-sm text-muted-foreground">No primary domain configured</p>
          )}
        </CardContent>
      </Card>

      {/* Custom Domains */}
      <Card>
        <CardHeader>
          <CardTitle>Custom Domains</CardTitle>
          <CardDescription>Add additional domains that point to this application</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {/* Add Domain Form */}
          <form onSubmit={handleAddDomain} className="flex gap-2">
            <Input
              placeholder="your-domain.com"
              value={newDomain}
              onChange={(e) => setNewDomain(e.target.value)}
              className="flex-1"
            />
            <Button type="submit" disabled={adding || !newDomain.trim()}>
              {adding ? (
                <LoadingSpinner size="sm" />
              ) : (
                <>
                  <PlusIcon className="h-4 w-4 mr-2" />
                  Add Domain
                </>
              )}
            </Button>
          </form>

          {/* Alias Domains List */}
          {aliasDomains.length > 0 ? (
            <div className="space-y-2">
              {aliasDomains.map((domain) => (
                <DomainRow
                  key={domain.id}
                  domain={domain}
                  onDelete={handleDeleteDomain}
                  onSetPrimary={handleSetPrimary}
                  onRequestSsl={openSslModal}
                  showPrimaryAction={true}
                  canDelete={true}
                />
              ))}
            </div>
          ) : (
            <p className="text-sm text-muted-foreground py-4 text-center border rounded-lg">
              No additional domains configured
            </p>
          )}
        </CardContent>
      </Card>

      {/* SSL Certificates */}
      <Card>
        <CardHeader>
          <CardTitle>Certificates</CardTitle>
          <CardDescription>SSL certificates for your domains</CardDescription>
        </CardHeader>
        <CardContent>
          {sslEnabledDomains.length > 0 ? (
            <div className="space-y-2">
              {sslEnabledDomains.map((domain) => (
                <div
                  key={domain.id}
                  className="flex items-center justify-between p-3 border rounded-lg"
                >
                  <div className="flex items-center gap-3">
                    <ShieldCheckIcon className="h-5 w-5 text-green-500" />
                    <div>
                      <p className="font-medium">{domain.domain}</p>
                      <p className="text-sm text-muted-foreground">
                        {domain.ssl_issuer || "Let's Encrypt"}
                      </p>
                    </div>
                  </div>
                  <div className="text-sm text-muted-foreground">
                    {domain.ssl_expires_at ? (
                      <span>Expires in {formatExpiryDate(domain.ssl_expires_at)}</span>
                    ) : (
                      <span>Active</span>
                    )}
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-sm text-muted-foreground py-4 text-center border rounded-lg">
              No SSL certificates configured
            </p>
          )}
        </CardContent>
      </Card>

      {/* Nginx Config Modal */}
      <NginxConfigModal
        appId={parseInt(id!)}
        open={nginxModalOpen}
        onOpenChange={setNginxModalOpen}
      />

      {/* SSL Request Modal */}
      <Dialog open={sslModalOpen} onOpenChange={setSslModalOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Request SSL Certificate</DialogTitle>
            <DialogDescription>
              Request a free SSL certificate from Let's Encrypt for {selectedDomain?.domain}
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleRequestSsl}>
            <div className="space-y-4 py-4">
              <div className="space-y-2">
                <label htmlFor="ssl-email" className="text-sm font-medium">
                  Email Address
                </label>
                <Input
                  id="ssl-email"
                  type="email"
                  placeholder="admin@example.com"
                  value={sslEmail}
                  onChange={(e) => setSslEmail(e.target.value)}
                  required
                />
                <p className="text-xs text-muted-foreground">
                  This email will receive certificate expiry notifications from Let's Encrypt.
                </p>
              </div>
            </div>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setSslModalOpen(false)}
                disabled={requestingSsl}
              >
                Cancel
              </Button>
              <Button type="submit" disabled={requestingSsl || !sslEmail.trim()}>
                {requestingSsl ? (
                  <>
                    <LoadingSpinner size="sm" className="mr-2" />
                    Requesting...
                  </>
                ) : (
                  'Request Certificate'
                )}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  )
}

interface DomainRowProps {
  domain: Domain
  onDelete: (domain: Domain) => void
  onSetPrimary: (domain: Domain) => void
  onRequestSsl: (domain: Domain) => void
  showPrimaryAction: boolean
  canDelete: boolean
}

function DomainRow({
  domain,
  onDelete,
  onSetPrimary,
  onRequestSsl,
  showPrimaryAction,
  canDelete,
}: DomainRowProps) {
  return (
    <div className="flex items-center justify-between p-4 border rounded-lg">
      <div className="flex items-center gap-3">
        {domain.ssl_enabled ? (
          <LockClosedIcon className="h-5 w-5 text-green-500" />
        ) : (
          <GlobeAltIcon className="h-5 w-5 text-muted-foreground" />
        )}
        <div>
          <div className="flex items-center gap-2">
            <p className="font-medium">{domain.domain}</p>
            {domain.is_primary && (
              <span className="text-xs bg-primary/10 text-primary px-2 py-0.5 rounded">Primary</span>
            )}
          </div>
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            {domain.ssl_enabled ? (
              <>
                <LockClosedIcon className="h-3 w-3 text-green-500" />
                <span>SSL Enabled</span>
              </>
            ) : (
              <>
                <LockOpenIcon className="h-3 w-3" />
                <span>No SSL</span>
              </>
            )}
          </div>
        </div>
      </div>

      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="sm">
            <EllipsisVerticalIcon className="h-5 w-5" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          {!domain.ssl_enabled && (
            <DropdownMenuItem onClick={() => onRequestSsl(domain)}>
              <ShieldCheckIcon className="h-4 w-4 mr-2" />
              Enable SSL
            </DropdownMenuItem>
          )}
          {showPrimaryAction && (
            <DropdownMenuItem onClick={() => onSetPrimary(domain)}>
              <StarIcon className="h-4 w-4 mr-2" />
              Set as Primary
            </DropdownMenuItem>
          )}
          {canDelete && (
            <DropdownMenuItem
              onClick={() => onDelete(domain)}
              className="text-destructive focus:text-destructive"
            >
              <TrashIcon className="h-4 w-4 mr-2" />
              Remove
            </DropdownMenuItem>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  )
}
