import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { organizationsApi, getErrorMessage } from '../../services/api'
import { useAuth } from '@/hooks/useAuth'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
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

export function OrganizationGeneral() {
  const { currentOrganization, organizations, hasRole, refreshUser } = useAuth()
  const [name, setName] = useState(currentOrganization?.name ?? '')
  const [saving, setSaving] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [deleting, setDeleting] = useState(false)

  useEffect(() => {
    setName(currentOrganization?.name ?? '')
  }, [currentOrganization?.name])

  const canEdit = hasRole('owner', 'admin')
  const isOwner = hasRole('owner')

  const handleSave = async () => {
    if (!currentOrganization || !name.trim()) return
    setSaving(true)
    try {
      await organizationsApi.update(currentOrganization.id, { name: name.trim() })
      await refreshUser()
      toast.success('Organization updated')
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to update organization'))
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!currentOrganization) return
    setDeleting(true)
    try {
      await organizationsApi.delete(currentOrganization.id)
      // The backend repoints the session to another membership (or none);
      // reload so every org-scoped context starts fresh.
      window.location.assign('/app/')
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to delete organization'))
      setDeleting(false)
      setConfirmDelete(false)
    }
  }

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle>General</CardTitle>
          <CardDescription>
            Settings for {currentOrganization?.name ?? 'your organization'}.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div>
            <div className="flex items-start justify-between py-6 border-b gap-6">
              <div>
                <p className="font-medium">Organization name</p>
                <p className="text-sm text-muted-foreground">
                  The name of your organization.
                </p>
              </div>
              {canEdit ? (
                <div className="flex items-center gap-2">
                  <Input
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    maxLength={100}
                    className="w-56"
                  />
                  <Button
                    onClick={handleSave}
                    disabled={saving || !name.trim() || name.trim() === currentOrganization?.name}
                  >
                    {saving ? 'Saving...' : 'Save'}
                  </Button>
                </div>
              ) : (
                <div className="text-sm">{currentOrganization?.name}</div>
              )}
            </div>

            <div className="flex items-start justify-between py-6">
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

      {isOwner && (
        <Card className="border-destructive/50">
          <CardHeader>
            <CardTitle>Danger Zone</CardTitle>
            <CardDescription>
              Deleting an organization is permanent. All servers, git providers
              and notification channels must be removed first.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex items-start justify-between">
              <div>
                <p className="font-medium">Delete this organization</p>
                <p className="text-sm text-muted-foreground">
                  {organizations.length <= 1
                    ? 'You cannot delete your only organization.'
                    : 'Members lose access immediately.'}
                </p>
              </div>
              <Button
                variant="destructive"
                onClick={() => setConfirmDelete(true)}
                disabled={organizations.length <= 1}
              >
                Delete organization
              </Button>
            </div>
          </CardContent>
        </Card>
      )}

      <AlertDialog open={confirmDelete} onOpenChange={setConfirmDelete}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete {currentOrganization?.name}?</AlertDialogTitle>
            <AlertDialogDescription>
              This permanently deletes the organization and removes access for
              all of its members. This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deleting}>Cancel</AlertDialogCancel>
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
