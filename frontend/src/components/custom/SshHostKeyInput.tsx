import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'

export function SshHostKeyInput({ value, onChange, trustOnFirstUse = false }: { value: string; onChange: (value: string) => void; trustOnFirstUse?: boolean }) {
  return (
    <div className="space-y-2 py-4">
      <Label htmlFor="ssh_host_key">Trusted SSH host public key{trustOnFirstUse && ' (optional)'}</Label>
      <Textarea id="ssh_host_key" value={value} onChange={e => onChange(e.target.value)}
        placeholder="ssh-ed25519 AAAA…" rows={3} className="font-mono text-xs" />
      <p className="text-xs text-muted-foreground">
        {trustOnFirstUse ? (
          <>Leave blank to automatically trust and save the server’s host key on the first successful connection.
            You can also paste a verified key here. Future connections stop if the host key changes.
            This is separate from your SSH login key.</>
        ) : (
          <>Required for SSH connections. Obtain this key from your server console
            (for example, /etc/ssh/ssh_host_ed25519_key.pub) or the Git provider’s official documentation.
            Saving it explicitly trusts that host. Verify any replacement through a trusted channel;
            connections stop if the host presents a different key. This is separate from your login key.</>
        )}
      </p>
    </div>
  )
}
