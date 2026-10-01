import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'

export function SshHostKeyInput({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  return (
    <div className="space-y-2 py-4">
      <Label htmlFor="ssh_host_key">Trusted SSH host public key</Label>
      <Textarea id="ssh_host_key" value={value} onChange={e => onChange(e.target.value)}
        placeholder="ssh-ed25519 AAAA…" rows={3} className="font-mono text-xs" />
      <p className="text-xs text-muted-foreground">
        Required for SSH connections. Obtain this key from your server console
        (for example, /etc/ssh/ssh_host_ed25519_key.pub) or the Git provider’s official documentation.
        Saving it explicitly trusts that host. Verify any replacement through a trusted channel;
        connections stop if the host presents a different key. This is separate from your login key.
      </p>
    </div>
  )
}
