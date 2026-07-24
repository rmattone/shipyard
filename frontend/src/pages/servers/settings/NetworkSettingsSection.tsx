import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import {
  firewallApi,
  getErrorMessage,
  Server,
  FirewallStatus,
  FirewallRule,
  FirewallRuleSpec,
} from '@/services/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { Alert, AlertDescription } from '@/components/ui/alert'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
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
import { LoadingSpinner } from '@/components/custom'
import { Check, Plus, Trash2 } from 'lucide-react'

interface NetworkSettingsSectionProps {
  server: Server
}

// Only rows shaped exactly like what this panel itself can produce
// ("port[/proto]" with an IPv4/CIDR or absent source) are safe to turn back
// into a delete spec. App profile names, interface-scoped sources (e.g.
// "Anywhere on eth0"), protocol-less rules, and v6 rows are left alone.
const TO_PATTERN = /^(\d{1,5}(?::\d{1,5})?)\/(tcp|udp)$/
const IPV4_CIDR_PATTERN = /^(\d{1,3}\.){3}\d{1,3}(\/\d{1,2})?$/

function parseRuleSpec(rule: FirewallRule): FirewallRuleSpec | null {
  if (rule.v6) return null

  const toMatch = rule.to.match(TO_PATTERN)
  if (!toMatch) return null
  const [, port, protocol] = toMatch

  if (rule.from === 'Anywhere') {
    return { port, protocol: protocol as 'tcp' | 'udp', source: null }
  }
  if (IPV4_CIDR_PATTERN.test(rule.from)) {
    return { port, protocol: protocol as 'tcp' | 'udp', source: rule.from }
  }
  return null
}

interface ServicePreset {
  name: string
  port: string
  requiresSource: boolean
}

const SERVICE_PRESETS: ServicePreset[] = [
  { name: 'HTTP', port: '80', requiresSource: false },
  { name: 'HTTPS', port: '443', requiresSource: false },
  { name: 'MySQL', port: '3306', requiresSource: true },
  { name: 'PostgreSQL', port: '5432', requiresSource: true },
  { name: 'Redis', port: '6379', requiresSource: true },
]

// Exact-shape match only ("<port>/tcp" IPv4 ALLOW IN). Manually created
// overlapping rules (protocol-less or interface-scoped) will show as not
// allowed; clicking the preset then just adds a redundant exact rule.
function isPortAllowed(rules: FirewallRule[], port: string): boolean {
  return rules.some(
    (rule) => !rule.v6 && rule.to === `${port}/tcp` && rule.action === 'ALLOW IN'
  )
}

export default function NetworkSettingsSection({ server }: NetworkSettingsSectionProps) {
  const [status, setStatus] = useState<FirewallStatus | null>(null)
  const [loading, setLoading] = useState(true)
  const [firewallError, setFirewallError] = useState<string | null>(null)
  const [installing, setInstalling] = useState(false)
  const [toggling, setToggling] = useState(false)
  const [showEnableConfirm, setShowEnableConfirm] = useState(false)

  const [showAddRuleDialog, setShowAddRuleDialog] = useState(false)
  const [ruleForm, setRuleForm] = useState<{ port: string; protocol: 'tcp' | 'udp' | 'both'; source: string }>({
    port: '',
    protocol: 'tcp',
    source: '',
  })
  const [addingRule, setAddingRule] = useState(false)
  const [ruleToDelete, setRuleToDelete] = useState<FirewallRule | null>(null)
  const [deletingRule, setDeletingRule] = useState(false)
  const [addingPresetPort, setAddingPresetPort] = useState<string | null>(null)
  const [activePreset, setActivePreset] = useState<ServicePreset | null>(null)

  useEffect(() => {
    setLoading(true)
    setFirewallError(null)
    firewallApi.status(server.id)
      .then(response => setStatus(response.data))
      .catch((error: unknown) => setFirewallError(getErrorMessage(error, 'Could not read firewall status')))
      .finally(() => setLoading(false))
  }, [server.id])

  const handleInstall = async () => {
    setInstalling(true)
    try {
      const response = await firewallApi.install(server.id)
      setStatus(response.data)
      toast.success('UFW installed')
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, 'Failed to install UFW'))
    } finally {
      setInstalling(false)
    }
  }

  const handleEnable = async () => {
    setToggling(true)
    try {
      const response = await firewallApi.enable(server.id)
      setStatus(response.data)
      toast.success('Firewall enabled')
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, 'Failed to enable firewall'))
    } finally {
      setToggling(false)
      setShowEnableConfirm(false)
    }
  }

  const handleDisable = async () => {
    setToggling(true)
    try {
      const response = await firewallApi.disable(server.id)
      setStatus(response.data)
      toast.success('Firewall disabled')
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, 'Failed to disable firewall'))
    } finally {
      setToggling(false)
    }
  }

  const openAddRuleDialog = () => {
    setActivePreset(null)
    setRuleForm({ port: '', protocol: 'tcp', source: '' })
    setShowAddRuleDialog(true)
  }

  const closeAddRuleDialog = () => {
    setShowAddRuleDialog(false)
    setActivePreset(null)
  }

  const handlePresetClick = async (preset: ServicePreset) => {
    if (preset.requiresSource) {
      setActivePreset(preset)
      setRuleForm({ port: preset.port, protocol: 'tcp', source: '' })
      setShowAddRuleDialog(true)
      return
    }
    setAddingPresetPort(preset.port)
    try {
      const response = await firewallApi.addRule(server.id, {
        port: preset.port,
        protocol: 'tcp',
        source: null,
      })
      setStatus(response.data)
      toast.success(`${preset.name} (${preset.port}/tcp) allowed`)
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, 'Failed to add firewall rule'))
    } finally {
      setAddingPresetPort(null)
    }
  }

  const handleAddRule = async () => {
    if (!ruleForm.port.trim()) {
      toast.error('Please enter a port')
      return
    }
    if (activePreset?.requiresSource && !ruleForm.source.trim()) {
      toast.error('Enter a source IP to restrict database access')
      return
    }
    setAddingRule(true)
    try {
      const response = await firewallApi.addRule(server.id, {
        port: ruleForm.port.trim(),
        protocol: ruleForm.protocol,
        source: ruleForm.source.trim() || null,
      })
      setStatus(response.data)
      toast.success('Firewall rule added')
      closeAddRuleDialog()
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, 'Failed to add firewall rule'))
    } finally {
      setAddingRule(false)
    }
  }

  const handleDeleteRule = async () => {
    if (!ruleToDelete) return
    const spec = parseRuleSpec(ruleToDelete)
    if (!spec) {
      setRuleToDelete(null)
      return
    }
    setDeletingRule(true)
    try {
      const response = await firewallApi.deleteRule(server.id, spec)
      setStatus(response.data)
      toast.success('Firewall rule removed')
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, 'Failed to remove firewall rule'))
    } finally {
      setDeletingRule(false)
      setRuleToDelete(null)
    }
  }

  return (
    <TooltipProvider>
      <Card>
        <CardHeader>
          <CardTitle>Firewall (UFW)</CardTitle>
          <CardDescription>
            Manage the uncomplicated firewall on this server.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {firewallError && (
            <Alert variant="destructive">
              <AlertDescription>{firewallError}</AlertDescription>
            </Alert>
          )}

          {loading ? (
            <div className="flex items-center justify-center py-8">
              <LoadingSpinner size="lg" />
            </div>
          ) : status && !status.installed ? (
            <div className="flex items-start justify-between py-4">
              <div>
                <p className="font-medium">UFW is not installed</p>
                <p className="text-sm text-muted-foreground">
                  Install UFW on this server to manage firewall rules from here.
                </p>
              </div>
              <Button onClick={handleInstall} disabled={installing}>
                {installing && <LoadingSpinner size="sm" className="mr-2" />}
                Install UFW
              </Button>
            </div>
          ) : status ? (
            <>
              <div className="flex items-start justify-between py-4 border-b">
                <div>
                  <p className="font-medium flex items-center gap-2">
                    Firewall status
                    <Badge variant={status.active ? 'default' : 'secondary'}>
                      {status.active ? 'active' : 'inactive'}
                    </Badge>
                  </p>
                  <p className="text-sm text-muted-foreground">
                    Enable or disable the firewall on this server.
                  </p>
                </div>
                <Switch
                  checked={status.active}
                  disabled={toggling}
                  onCheckedChange={(checked) => {
                    if (checked) {
                      setShowEnableConfirm(true)
                    } else {
                      handleDisable()
                    }
                  }}
                />
              </div>

              <div className="space-y-3 pb-4 border-b">
                <div>
                  <p className="font-medium">Common services</p>
                  <p className="text-sm text-muted-foreground">
                    Websites served from this server need HTTP (80) and HTTPS (443) open to
                    anywhere. Port 80 is also required for Let&apos;s Encrypt certificate
                    challenges. Database ports should only be opened to specific trusted IPs,
                    since applications running on this server connect over localhost.
                  </p>
                </div>
                <div className="flex flex-wrap gap-2">
                  {SERVICE_PRESETS.map((preset) => {
                    const allowed = isPortAllowed(status.rules, preset.port)
                    return (
                      <Button
                        key={preset.port}
                        variant="outline"
                        size="sm"
                        disabled={allowed || addingPresetPort !== null}
                        onClick={() => handlePresetClick(preset)}
                      >
                        {addingPresetPort === preset.port ? (
                          <LoadingSpinner size="sm" className="mr-2" />
                        ) : allowed ? (
                          <Check className="h-4 w-4 mr-2" />
                        ) : (
                          <Plus className="h-4 w-4 mr-2" />
                        )}
                        {preset.name} {preset.port}
                        {allowed && <span className="ml-1 text-muted-foreground">allowed</span>}
                      </Button>
                    )
                  })}
                </div>
              </div>

              <div className="flex items-center justify-between">
                <p className="font-medium">Rules</p>
                <Button variant="outline" size="sm" onClick={openAddRuleDialog}>
                  <Plus className="h-4 w-4 mr-2" />
                  Add rule
                </Button>
              </div>

              {status.rules.length === 0 ? (
                <div className="text-center py-8 text-muted-foreground">
                  <p>No rules configured.</p>
                </div>
              ) : (
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>To</TableHead>
                      <TableHead>Action</TableHead>
                      <TableHead>From</TableHead>
                      <TableHead></TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {status.rules.map((rule) => {
                      const spec = parseRuleSpec(rule)
                      return (
                        <TableRow key={rule.number}>
                          <TableCell className="font-mono text-xs">
                            {rule.to}{rule.v6 && <span className="text-muted-foreground"> (v6)</span>}
                          </TableCell>
                          <TableCell>{rule.action}</TableCell>
                          <TableCell className="font-mono text-xs">{rule.from}</TableCell>
                          <TableCell className="text-right">
                            {spec ? (
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setRuleToDelete(rule)}
                              >
                                <Trash2 className="h-4 w-4 text-destructive" />
                              </Button>
                            ) : (
                              <Tooltip>
                                <TooltipTrigger asChild>
                                  <span tabIndex={0}>
                                    <Button variant="ghost" size="sm" disabled>
                                      <Trash2 className="h-4 w-4" />
                                    </Button>
                                  </span>
                                </TooltipTrigger>
                                <TooltipContent side="left">
                                  This rule shape can't be safely removed from here; delete it with ufw on the server.
                                </TooltipContent>
                              </Tooltip>
                            )}
                          </TableCell>
                        </TableRow>
                      )
                    })}
                  </TableBody>
                </Table>
              )}
            </>
          ) : null}
        </CardContent>
      </Card>

      {/* Add rule dialog */}
      <Dialog
        open={showAddRuleDialog}
        onOpenChange={(open) => {
          if (open) {
            setShowAddRuleDialog(true)
          } else {
            closeAddRuleDialog()
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {activePreset
                ? `Allow ${activePreset.name} (${activePreset.port}/tcp)`
                : 'Add firewall rule'}
            </DialogTitle>
            <DialogDescription>
              {activePreset
                ? 'Database ports must be restricted to a trusted source IP.'
                : 'Allow traffic to a port, optionally restricted to a single source.'}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="rule-port">Port</Label>
              <Input
                id="rule-port"
                value={ruleForm.port}
                onChange={(e) => setRuleForm({ ...ruleForm, port: e.target.value })}
                placeholder="8080 or 3000:3005"
                disabled={!!activePreset}
              />
            </div>
            <div className="space-y-2">
              <Label>Protocol</Label>
              <Select
                value={ruleForm.protocol}
                onValueChange={(value) => setRuleForm({ ...ruleForm, protocol: value as 'tcp' | 'udp' | 'both' })}
                disabled={!!activePreset}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="tcp">tcp</SelectItem>
                  <SelectItem value="udp">udp</SelectItem>
                  <SelectItem value="both">both</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="rule-source">
                {activePreset ? 'Source' : 'Source (optional)'}
              </Label>
              <Input
                id="rule-source"
                value={ruleForm.source}
                onChange={(e) => setRuleForm({ ...ruleForm, source: e.target.value })}
                placeholder={
                  activePreset
                    ? '203.0.113.10 or 10.0.0.0/24'
                    : 'leave blank for anywhere, or 10.0.0.0/24'
                }
              />
              {activePreset && (
                <p className="text-sm text-muted-foreground">
                  Restrict database access to a trusted IP. Never open database ports to
                  the whole internet.
                </p>
              )}
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={closeAddRuleDialog}>
              Cancel
            </Button>
            <Button
              onClick={handleAddRule}
              disabled={addingRule || (!!activePreset?.requiresSource && !ruleForm.source.trim())}
            >
              {addingRule && <LoadingSpinner size="sm" className="mr-2" />}
              Add Rule
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Enable confirmation */}
      <AlertDialog open={showEnableConfirm} onOpenChange={setShowEnableConfirm}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Enable firewall</AlertDialogTitle>
            <AlertDialogDescription>
              ShipYard automatically allows your SSH port before enabling so you won't be
              locked out.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={toggling}>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={handleEnable} disabled={toggling}>
              {toggling && <LoadingSpinner size="sm" className="mr-2" />}
              Enable
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Delete rule confirmation */}
      <AlertDialog open={!!ruleToDelete} onOpenChange={(open) => { if (!open) setRuleToDelete(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Remove firewall rule</AlertDialogTitle>
            <AlertDialogDescription>
              Remove the rule allowing {ruleToDelete?.to} from {ruleToDelete?.from}?
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deletingRule}>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDeleteRule}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              disabled={deletingRule}
            >
              {deletingRule && <LoadingSpinner size="sm" className="mr-2" />}
              Remove
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </TooltipProvider>
  )
}
