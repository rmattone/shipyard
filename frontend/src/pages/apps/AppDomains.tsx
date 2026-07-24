import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { applicationsApi, domainsApi, Domain, Application } from '../../services/api'
import { toast } from 'sonner'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
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
  LockClosedIcon,
  LockOpenIcon,
  EllipsisHorizontalIcon,
  TrashIcon,
  StarIcon,
  ShieldCheckIcon,
  PencilIcon,
  CheckCircleIcon,
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

  // Primary domain first, then aliases
  const sortedDomains = [...domains].sort((a, b) => Number(b.is_primary) - Number(a.is_primary))
  const sslEnabledDomains = domains.filter((d) => d.ssl_enabled)

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Domains</h1>
        <Button variant="outline" onClick={() => setNginxModalOpen(true)}>
          <PencilIcon className="h-4 w-4 mr-2" />
          Edit Nginx configuration
        </Button>
      </div>

      {/* Domains */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Domains</CardTitle>
          <CardDescription>
            Manage your application's domains and SSL certificates.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="rounded-lg border p-6 space-y-5">
            <div className="space-y-1">
              <h3 className="text-sm font-medium">Custom domains</h3>
              <p className="text-sm text-muted-foreground">
                Add custom domains and aliases that you own.
              </p>
            </div>

            {/* Add Domain Form */}
            <form onSubmit={handleAddDomain} className="flex">
              <Input
                placeholder="your-domain.com"
                value={newDomain}
                onChange={(e) => setNewDomain(e.target.value)}
                className="flex-1 rounded-r-none"
              />
              <Button
                type="submit"
                variant="outline"
                className="rounded-l-none border-l-0"
                disabled={adding || !newDomain.trim()}
              >
                {adding ? <LoadingSpinner size="sm" /> : 'Add domain'}
              </Button>
            </form>

            {/* Domains List */}
            {sortedDomains.length > 0 ? (
              <div className="space-y-3">
                {sortedDomains.map((domain) => (
                  <DomainRow
                    key={domain.id}
                    domain={domain}
                    onDelete={handleDeleteDomain}
                    onSetPrimary={handleSetPrimary}
                    onRequestSsl={openSslModal}
                    showPrimaryAction={!domain.is_primary}
                    canDelete={!domain.is_primary || domains.length > 1}
                  />
                ))}
              </div>
            ) : (
              <p className="text-sm text-muted-foreground py-6 text-center border rounded-lg">
                No domains configured
              </p>
            )}
          </div>
        </CardContent>
      </Card>

      {/* SSL Certificates */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Certificates</CardTitle>
          <CardDescription>Manage your application's SSL certificates.</CardDescription>
        </CardHeader>
        <CardContent>
          {sslEnabledDomains.length > 0 ? (
            <div className="space-y-3">
              {sslEnabledDomains.map((domain) => (
                <div
                  key={domain.id}
                  className="flex items-center justify-between rounded-lg border px-4 py-4"
                >
                  <div>
                    <p className="text-sm font-medium">{domain.ssl_issuer || "Let's Encrypt"}</p>
                    <p className="text-sm text-muted-foreground">{domain.domain}</p>
                  </div>
                  <div className="flex items-center gap-3">
                    {domain.ssl_expires_at && (
                      <span className="text-sm text-muted-foreground">
                        Expires in {formatExpiryDate(domain.ssl_expires_at)}
                      </span>
                    )}
                    <Badge
                      variant="outline"
                      className="gap-1.5 border-emerald-500/30 bg-emerald-500/10 text-emerald-500"
                    >
                      <CheckCircleIcon className="h-3.5 w-3.5" />
                      Active
                    </Badge>
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-sm text-muted-foreground py-6 text-center border rounded-lg">
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
    <div className="flex items-center justify-between rounded-lg border px-4 py-4">
      <div className="flex items-center gap-2.5">
        <p className="text-sm font-medium">{domain.domain}</p>
        {domain.is_primary && <Badge variant="outline">Primary</Badge>}
      </div>

      <div className="flex items-center gap-3">
        <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
          {domain.ssl_enabled ? (
            <>
              <LockClosedIcon className="h-3.5 w-3.5 text-emerald-500" />
              SSL enabled
            </>
          ) : (
            <>
              <LockOpenIcon className="h-3.5 w-3.5" />
              No SSL
            </>
          )}
        </span>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="sm" className="h-8 w-8 p-0">
              <EllipsisHorizontalIcon className="h-5 w-5" />
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
    </div>
  )
}
