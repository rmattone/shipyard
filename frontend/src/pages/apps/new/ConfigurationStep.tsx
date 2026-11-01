import { Database } from '@/services/api'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Configuration } from './setup'

export function ConfigurationStep({ value, onChange, databases, databaseError, loadingDatabases, type }: {
  value: Configuration
  onChange: (value: Configuration) => void
  databases: Database[]
  databaseError: boolean
  loadingDatabases: boolean
  type: string
}) {
  const localDatabases = databases.filter(database => ['localhost', '127.0.0.1'].includes(database.host))
  return <div className="space-y-7">
    <div className="space-y-2">
      <Label htmlFor="domain">Domain (optional)</Label>
      <Input id="domain" placeholder="app.example.com" value={value.domain}
        pattern={String.raw`([a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}`} maxLength={253}
        onChange={event => onChange({ ...value, domain: event.target.value })} />
      <p className="text-xs text-muted-foreground">Enter a hostname without https://. Point its DNS at this server. HTTPS can be enabled after deployment.</p>
    </div>
    <div className="space-y-3">
      <Label htmlFor="environment">Environment variables</Label>
      <Textarea id="environment" rows={9} className="font-mono text-xs" autoComplete="off" spellCheck={false}
        placeholder={'APP_ENV=production\nAPP_DEBUG=false\n# Paste your application’s .env here'}
        value={value.environment} onChange={event => onChange({ ...value, environment: event.target.value })} />
      <label className="flex items-start gap-2 text-sm">
        <input type="checkbox" className="mt-1" checked={value.productionDefaults} onChange={event => onChange({ ...value, productionDefaults: event.target.checked })} />
        <span>Start with production defaults, then apply the variables above</span>
      </label>
      <p className="text-xs text-muted-foreground">Defaults use the repository’s .env.example when accessible, otherwise ShipYard’s template. Laravel defaults generate an APP_KEY and disable debug. Your entries override defaults. Leaving both options empty preserves a server-managed environment.</p>
    </div>
    {type !== 'static' && <div className="space-y-4 border-t pt-5">
      <div className="space-y-2">
        <Label>Database (optional)</Label>
        <Select value={value.databaseId} disabled={loadingDatabases} onValueChange={databaseId => onChange({ ...value, databaseId })}>
          <SelectTrigger><SelectValue placeholder="Choose a database connection" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="none">Configure my own / no new database</SelectItem>
            {localDatabases.map(database => <SelectItem key={database.id} value={String(database.id)}>Create on {database.name} ({database.type})</SelectItem>)}
          </SelectContent>
        </Select>
        <p className="text-xs text-muted-foreground">{loadingDatabases ? 'Loading installed database connections…' : databaseError ? 'Could not load database connections. You can still supply existing credentials in the environment.' : localDatabases.length === 0 ? 'No active local database connection found. Supply existing credentials above or add a connection in Server → Databases.' : 'Create a dedicated database and user, or supply existing credentials in the environment above.'}</p>
      </div>
      {value.databaseId !== 'none' && <>
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-2"><Label htmlFor="database-name">New database name</Label>
            <Input id="database-name" required pattern="[a-zA-Z_][a-zA-Z0-9_]*" maxLength={63} value={value.databaseName} placeholder="my_app" onChange={event => onChange({ ...value, databaseName: event.target.value })} /></div>
          <div className="space-y-2"><Label htmlFor="database-username">New database username</Label>
            <Input id="database-username" required pattern="[a-zA-Z_][a-zA-Z0-9_]*" maxLength={32} value={value.databaseUsername} placeholder="my_app" onChange={event => onChange({ ...value, databaseUsername: event.target.value })} /></div>
        </div>
        <div className="space-y-2"><Label htmlFor="database-password">Database password</Label>
          <div className="flex gap-2"><Input id="database-password" type="password" autoComplete="new-password" minLength={8} required value={value.databasePassword} onChange={event => onChange({ ...value, databasePassword: event.target.value })} />
            <Button type="button" variant="outline" onClick={() => onChange({ ...value, databasePassword: Array.from(crypto.getRandomValues(new Uint8Array(24)), byte => byte.toString(16).padStart(2, '0')).join('') })}>Generate</Button></div>
        </div>
        <p className="text-xs text-muted-foreground">ShipYard grants this user access only to the new database and writes DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD to the environment, overriding supplied database values and clearing DB_URL / DATABASE_URL. Node.js apps must read these DB_* variables.</p>
      </>}
    </div>}
  </div>
}
