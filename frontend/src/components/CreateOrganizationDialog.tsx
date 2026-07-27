import { useState } from 'react'
import { toast } from 'sonner'
import { organizationsApi, getErrorMessage } from '@/services/api'
import { useAuth } from '@/hooks/useAuth'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'

interface CreateOrganizationDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function CreateOrganizationDialog({ open, onOpenChange }: CreateOrganizationDialogProps) {
  const { switchOrganization } = useAuth()
  const [name, setName] = useState('')
  const [saving, setSaving] = useState(false)

  const handleCreate = async () => {
    if (!name.trim()) return
    setSaving(true)
    try {
      const response = await organizationsApi.create({ name: name.trim() })
      // The backend already switched the current organization; reload so
      // every org-scoped context starts fresh inside the new org.
      await switchOrganization(response.data.id)
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to create organization'))
      setSaving(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !saving && onOpenChange(next)}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Create organization</DialogTitle>
          <DialogDescription>
            Servers, applications and git providers are scoped to an organization.
            You will become its owner.
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-2">
          <Label htmlFor="organization-name">Name</Label>
          <Input
            id="organization-name"
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Acme Inc"
            maxLength={100}
            onKeyDown={(e) => e.key === 'Enter' && handleCreate()}
            autoFocus
          />
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
            Cancel
          </Button>
          <Button onClick={handleCreate} disabled={saving || !name.trim()}>
            {saving ? 'Creating...' : 'Create'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
