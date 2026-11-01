import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { applicationsApi, domainsApi, dnsProvidersApi, Domain, Application, DnsProvider, DnsConflict, DnsOptions } from '../../services/api'
import { toast } from 'sonner'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
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
  GlobeAltIcon,
  CloudIcon,
  ExclamationTriangleIcon,
} from '@heroicons/react/24/outline'

const NO_DNS = 'none'

type AxiosErr = { response?: { status?: number; data?: { message?: string } & Partial<DnsConflict> } }

// A pending DNS write that hit existing records and needs the operator's
// confirmation before ShipYard replaces them.
type PendingConflict = {
  conflict: DnsConflict
  retry: () => Promise<void>
}

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
  const [dnsProviders, setDnsProviders] = useState<DnsProvider[]>([])
  const [newDnsProvider, setNewDnsProvider] = useState<string>(NO_DNS)
  const [newDnsProxied, setNewDnsProxied] = useState(true)
  const [pendingConflict, setPendingConflict] = useState<PendingConflict | null>(null)
  const [resolvingConflict, setResolvingConflict] = useState(false)
  const [linkDomain, setLinkDomain] = useState<Domain | null>(null)
  const [linkProvider, setLinkProvider] = useState<string>('')
  const [linkProxied, setLinkProxied] = useState(true)
  const [linking, setLinking] = useState(false)
  const [unlinkDomain, setUnlinkDomain] = useState<Domain | null>(null)
  const [unlinkDeleteRecord, setUnlinkDeleteRecord] = useState(true)
  const [unlinking, setUnlinking] = useState(false)

  useEffect(() => {
    if (!id) return
    loadData()
  }, [id])

  const loadData = async () => {
    try {
      const [appResponse, domainsResponse, providersResponse] = await Promise.all([
        applicationsApi.get(parseInt(id!)),
        domainsApi.list(parseInt(id!)),
        // DNS is optional; the page works without it
        dnsProvidersApi.list().catch(() => ({ data: [] as DnsProvider[] })),
      ])
      setApp(appResponse.data)
      setDomains(domainsResponse.data)
      setDnsProviders(providersResponse.data)
      // With exactly one provider, managing DNS is the obvious default
      if (providersResponse.data.length === 1) {
        setNewDnsProvider(String(providersResponse.data[0].id))
      }
    } catch {
      toast.error('Failed to load application data')
    } finally {
      setLoading(false)
    }
  }

  const handleAddDomain = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!newDomain.trim()) return

    const name = newDomain.trim()
    const dns: DnsOptions = newDnsProvider === NO_DNS
      ? {}
      : { dns_provider_id: parseInt(newDnsProvider), dns_proxied: newDnsProxied }

    const create = async (overwrite: boolean) => {
      const response = await domainsApi.create(parseInt(id!), name, { ...dns, dns_overwrite: overwrite || undefined })
      setDomains(current => [...current, response.data])
      setNewDomain('')
      toast.success(dns.dns_provider_id ? 'Domain added and DNS record created' : 'Domain added successfully')
    }

    setAdding(true)
    try {
      await create(false)
    } catch (error: unknown) {
      if (!handleConflict(error, () => create(true))) {
        toast.error((error as AxiosErr).response?.data?.message || 'Failed to add domain')
      }
    } finally {
      setAdding(false)
    }
  }

  // Returns true when the error was a DNS conflict now waiting for the
  // operator to confirm the replacement.
  const handleConflict = (error: unknown, retry: () => Promise<void>): boolean => {
    const err = error as AxiosErr
    if (err.response?.status !== 409 || !err.response.data?.conflict) return false
    setPendingConflict({ conflict: err.response.data as DnsConflict, retry })
    return true
  }

  const confirmReplace = async () => {
    if (!pendingConflict) return
    setResolvingConflict(true)
    try {
      await pendingConflict.retry()
      setPendingConflict(null)
    } catch (error: unknown) {
      toast.error((error as AxiosErr).response?.data?.message || 'Failed to replace the DNS records')
    } finally {
      setResolvingConflict(false)
    }
  }

  const replaceDomain = (updated: Domain) =>
    setDomains(current => current.map(d => (d.id === updated.id ? updated : d)))

  const openLinkDialog = (domain: Domain) => {
    setLinkDomain(domain)
    setLinkProvider(dnsProviders[0] ? String(dnsProviders[0].id) : '')
    setLinkProxied(true)
  }

  const handleLinkDns = async () => {
    if (!linkDomain || !linkProvider) return
    const target = linkDomain
    const link = async (overwrite: boolean) => {
      const response = await domainsApi.linkDns(parseInt(id!), target.id, {
        dns_provider_id: parseInt(linkProvider),
        dns_proxied: linkProxied,
        dns_overwrite: overwrite || undefined,
      })
      replaceDomain(response.data)
      setLinkDomain(null)
      toast.success(`DNS for ${target.domain} is now managed by ShipYard`)
    }

    setLinking(true)
    try {
      await link(false)
    } catch (error: unknown) {
      if (!handleConflict(error, () => link(true))) {
        toast.error((error as AxiosErr).response?.data?.message || 'Failed to manage DNS')
      }
    } finally {
      setLinking(false)
    }
  }

  const handleToggleProxy = async (domain: Domain) => {
    try {
      const response = await domainsApi.setProxied(parseInt(id!), domain.id, !domain.dns_proxied)
      replaceDomain(response.data)
      if (response.data.dns_status === 'pending_proxy') {
        toast.success('The proxy turns on once the domain has an SSL certificate')
      } else {
        toast.success(response.data.dns_proxied ? 'Cloudflare proxy enabled' : 'Cloudflare proxy disabled')
      }
    } catch (error: unknown) {
      toast.error((error as AxiosErr).response?.data?.message || 'Failed to change the proxy setting')
    }
  }

  const openUnlinkDialog = (domain: Domain) => {
    setUnlinkDomain(domain)
    setUnlinkDeleteRecord(domain.dns_record_managed)
  }

  const handleUnlinkDns = async () => {
    if (!unlinkDomain) return
    setUnlinking(true)
    try {
      const response = await domainsApi.unlinkDns(parseInt(id!), unlinkDomain.id, unlinkDeleteRecord)
      replaceDomain(response.data)
      setUnlinkDomain(null)
      toast.success('DNS is no longer managed for this domain')
    } catch (error: unknown) {
      toast.error((error as AxiosErr).response?.data?.message || 'Failed to stop managing DNS')
    } finally {
      setUnlinking(false)
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
      if (response.data.domain.dns_status === 'error') {
        toast.warning(`Certificate obtained, but the Cloudflare proxy could not be enabled: ${response.data.domain.dns_error}`)
      } else {
        toast.success('SSL certificate obtained successfully')
      }
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

            {dnsProviders.length > 0 && (
              <div className="flex flex-wrap items-center gap-x-6 gap-y-3">
                <div className="flex items-center gap-2">
                  <Label className="text-sm font-normal text-muted-foreground">DNS record</Label>
                  <Select value={newDnsProvider} onValueChange={setNewDnsProvider}>
                    <SelectTrigger className="h-8 w-[220px]">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value={NO_DNS}>I'll create it myself</SelectItem>
                      {dnsProviders.map(p => (
                        <SelectItem key={p.id} value={String(p.id)}>Create in {p.name}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                {newDnsProvider !== NO_DNS && (
                  <div className="flex items-center gap-2">
                    <Checkbox
                      id="new-dns-proxied"
                      checked={newDnsProxied}
                      onCheckedChange={(checked) => setNewDnsProxied(checked === true)}
                    />
                    <Label htmlFor="new-dns-proxied" className="text-sm font-normal">
                      Proxy through Cloudflare (turns on after SSL)
                    </Label>
                  </div>
                )}
              </div>
            )}

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
                    onManageDns={dnsProviders.length > 0 ? openLinkDialog : undefined}
                    onToggleProxy={handleToggleProxy}
                    onUnlinkDns={openUnlinkDialog}
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

      {/* DNS conflict confirmation */}
      <Dialog open={!!pendingConflict} onOpenChange={(open) => !open && setPendingConflict(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Replace existing DNS records?</DialogTitle>
            <DialogDescription>
              This name already points somewhere else. Replacing the records moves its traffic to this server, which can take a live site offline.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-2 py-2">
            {pendingConflict?.conflict.records.map((record, index) => (
              <div key={index} className="flex items-center justify-between rounded-md border px-3 py-2 font-mono text-sm">
                <span>{record.type}</span>
                <span className="text-muted-foreground">{record.content}</span>
              </div>
            ))}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setPendingConflict(null)} disabled={resolvingConflict}>
              Cancel
            </Button>
            <Button variant="destructive" onClick={confirmReplace} disabled={resolvingConflict}>
              {resolvingConflict ? <LoadingSpinner size="sm" className="mr-2" /> : null}
              Replace records
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Manage DNS for an existing domain */}
      <Dialog open={!!linkDomain} onOpenChange={(open) => !open && setLinkDomain(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Manage DNS for {linkDomain?.domain}</DialogTitle>
            <DialogDescription>
              ShipYard points the record at this application's server. A record that already points here is adopted as it is.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-2">
            <div className="space-y-2">
              <Label>Provider</Label>
              <Select value={linkProvider} onValueChange={setLinkProvider}>
                <SelectTrigger>
                  <SelectValue placeholder="Select a provider" />
                </SelectTrigger>
                <SelectContent>
                  {dnsProviders.map(p => (
                    <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="flex items-center gap-2">
              <Checkbox
                id="link-dns-proxied"
                checked={linkProxied}
                onCheckedChange={(checked) => setLinkProxied(checked === true)}
              />
              <Label htmlFor="link-dns-proxied" className="font-normal">
                Proxy through Cloudflare{linkDomain && !linkDomain.ssl_enabled ? ' (turns on after SSL)' : ''}
              </Label>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setLinkDomain(null)} disabled={linking}>
              Cancel
            </Button>
            <Button onClick={handleLinkDns} disabled={linking || !linkProvider}>
              {linking ? <LoadingSpinner size="sm" className="mr-2" /> : null}
              Manage DNS
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Stop managing DNS */}
      <Dialog open={!!unlinkDomain} onOpenChange={(open) => !open && setUnlinkDomain(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Stop managing DNS for {unlinkDomain?.domain}?</DialogTitle>
            <DialogDescription>
              {unlinkDomain?.dns_record_managed
                ? 'ShipYard created this record. Deleting it takes the domain offline until another record points somewhere.'
                : 'ShipYard did not create this record, so it stays in Cloudflare as it is.'}
            </DialogDescription>
          </DialogHeader>
          {unlinkDomain?.dns_record_managed && (
            <div className="flex items-center gap-2 py-2">
              <Checkbox
                id="unlink-delete-record"
                checked={unlinkDeleteRecord}
                onCheckedChange={(checked) => setUnlinkDeleteRecord(checked === true)}
              />
              <Label htmlFor="unlink-delete-record" className="font-normal">Also delete the DNS record</Label>
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setUnlinkDomain(null)} disabled={unlinking}>
              Cancel
            </Button>
            <Button variant="destructive" onClick={handleUnlinkDns} disabled={unlinking}>
              {unlinking ? <LoadingSpinner size="sm" className="mr-2" /> : null}
              Stop managing
            </Button>
          </DialogFooter>
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
  onManageDns?: (domain: Domain) => void
  onToggleProxy: (domain: Domain) => void
  onUnlinkDns: (domain: Domain) => void
  showPrimaryAction: boolean
  canDelete: boolean
}

function DomainRow({
  domain,
  onDelete,
  onSetPrimary,
  onRequestSsl,
  onManageDns,
  onToggleProxy,
  onUnlinkDns,
  showPrimaryAction,
  canDelete,
}: DomainRowProps) {
  const managesDns = domain.dns_provider_id !== null
  return (
    <div className="flex items-center justify-between rounded-lg border px-4 py-4">
      <div className="flex items-center gap-2.5">
        <p className="text-sm font-medium">{domain.domain}</p>
        {domain.is_primary && <Badge variant="outline">Primary</Badge>}
      </div>

      <div className="flex items-center gap-3">
        {managesDns && <DnsBadge domain={domain} />}
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
            {!managesDns && onManageDns && (
              <DropdownMenuItem onClick={() => onManageDns(domain)}>
                <GlobeAltIcon className="h-4 w-4 mr-2" />
                Manage DNS in Cloudflare
              </DropdownMenuItem>
            )}
            {managesDns && (
              <DropdownMenuItem onClick={() => onToggleProxy(domain)}>
                <CloudIcon className="h-4 w-4 mr-2" />
                {domain.dns_proxied ? 'Disable Cloudflare proxy' : 'Enable Cloudflare proxy'}
              </DropdownMenuItem>
            )}
            {managesDns && (
              <DropdownMenuItem onClick={() => onUnlinkDns(domain)}>
                <GlobeAltIcon className="h-4 w-4 mr-2" />
                Stop managing DNS
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

function DnsBadge({ domain }: { domain: Domain }) {
  if (domain.dns_status === 'error') {
    return (
      <span
        className="flex items-center gap-1.5 text-sm text-red-700 dark:text-red-400"
        title={domain.dns_error ?? undefined}
      >
        <ExclamationTriangleIcon className="h-3.5 w-3.5" />
        DNS error
      </span>
    )
  }

  const label = domain.dns_status === 'pending_proxy'
    ? 'Proxy after SSL'
    : domain.dns_proxied ? 'Proxied' : 'DNS only'

  return (
    <span
      className="flex items-center gap-1.5 text-sm text-muted-foreground"
      title={domain.dns_record_managed ? 'Record created by ShipYard' : 'Existing record adopted by ShipYard'}
    >
      <CloudIcon className={domain.dns_proxied && domain.dns_status === 'active' ? 'h-3.5 w-3.5 text-orange-500' : 'h-3.5 w-3.5'} />
      {label}
    </span>
  )
}
