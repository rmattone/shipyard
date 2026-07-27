import { useCallback, useEffect, useState } from 'react'
import { toast } from 'sonner'
import {
  organizationsApi,
  getErrorMessage,
  OrganizationMember,
  OrganizationInvitation,
  OrganizationRole,
} from '../../services/api'
import { useAuth } from '@/hooks/useAuth'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { LoadingSpinner } from '@/components/custom'
import { TrashIcon, ClipboardIcon } from '@heroicons/react/24/outline'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
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

export function OrganizationMembers() {
  const { user, currentOrganization, hasRole } = useAuth()
  const [members, setMembers] = useState<OrganizationMember[]>([])
  const [invitations, setInvitations] = useState<OrganizationInvitation[]>([])
  const [loading, setLoading] = useState(true)
  const [removeTarget, setRemoveTarget] = useState<OrganizationMember | null>(null)
  const [removing, setRemoving] = useState(false)

  // Invite form
  const [inviteEmail, setInviteEmail] = useState('')
  const [inviteRole, setInviteRole] = useState<OrganizationRole>('member')
  const [inviting, setInviting] = useState(false)

  const isOwner = hasRole('owner')
  const organizationId = currentOrganization?.id

  const load = useCallback(async () => {
    if (!organizationId) return
    try {
      const membersResponse = await organizationsApi.members(organizationId)
      setMembers(membersResponse.data)

      if (isOwner) {
        const invitationsResponse = await organizationsApi.invitations(organizationId)
        setInvitations(invitationsResponse.data)
      }
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to load members'))
    } finally {
      setLoading(false)
    }
  }, [organizationId, isOwner])

  useEffect(() => {
    load()
  }, [load])

  const handleRoleChange = async (member: OrganizationMember, role: OrganizationRole) => {
    if (!organizationId || role === member.role) return
    try {
      await organizationsApi.updateMemberRole(organizationId, member.id, role)
      setMembers(members.map(m => (m.id === member.id ? { ...m, role } : m)))
      toast.success(`${member.name} is now ${role === 'owner' ? 'an owner' : `a${role === 'admin' ? 'n' : ''} ${role}`}`)
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to change role'))
    }
  }

  const handleRemove = async () => {
    if (!organizationId || !removeTarget) return
    setRemoving(true)
    try {
      await organizationsApi.removeMember(organizationId, removeTarget.id)
      setMembers(members.filter(m => m.id !== removeTarget.id))
      toast.success('Member removed')
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to remove member'))
    } finally {
      setRemoving(false)
      setRemoveTarget(null)
    }
  }

  const handleInvite = async () => {
    if (!organizationId || !inviteEmail.trim()) return
    setInviting(true)
    try {
      const response = await organizationsApi.invite(organizationId, {
        email: inviteEmail.trim(),
        role: inviteRole,
      })
      setInvitations([response.data, ...invitations.filter(i => i.email !== response.data.email)])
      setInviteEmail('')
      if (response.data.accept_url) {
        await copyAcceptUrl(response.data.accept_url, 'Invitation created. Accept link copied to clipboard.')
      } else {
        toast.success('Invitation created')
      }
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to send invitation'))
    } finally {
      setInviting(false)
    }
  }

  const copyAcceptUrl = async (url: string, message = 'Accept link copied to clipboard') => {
    try {
      await navigator.clipboard.writeText(url)
      toast.success(message)
    } catch {
      toast.info(url, { duration: 15000 })
    }
  }

  const handleRevoke = async (invitation: OrganizationInvitation) => {
    if (!organizationId) return
    try {
      await organizationsApi.revokeInvitation(organizationId, invitation.id)
      setInvitations(invitations.filter(i => i.id !== invitation.id))
      toast.success('Invitation revoked')
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to revoke invitation'))
    }
  }

  const roleBadgeVariant = (role: OrganizationRole) =>
    role === 'owner' ? 'default' : role === 'admin' ? 'secondary' : 'outline'

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle>Members</CardTitle>
          <CardDescription>
            People with access to {currentOrganization?.name}.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Name</TableHead>
                <TableHead>Email</TableHead>
                <TableHead>Role</TableHead>
                {isOwner && <TableHead className="w-[60px]"></TableHead>}
              </TableRow>
            </TableHeader>
            <TableBody>
              {members.map((member) => {
                const isSelf = member.id === user?.id
                return (
                  <TableRow key={member.id}>
                    <TableCell className="font-medium">
                      {member.name}
                      {isSelf && <span className="text-muted-foreground ml-2 text-xs">(you)</span>}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{member.email}</TableCell>
                    <TableCell>
                      {isOwner && !isSelf ? (
                        <Select
                          value={member.role}
                          onValueChange={(role) => handleRoleChange(member, role as OrganizationRole)}
                        >
                          <SelectTrigger className="w-32">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="owner">Owner</SelectItem>
                            <SelectItem value="admin">Admin</SelectItem>
                            <SelectItem value="member">Member</SelectItem>
                          </SelectContent>
                        </Select>
                      ) : (
                        <Badge variant={roleBadgeVariant(member.role)} className="capitalize">
                          {member.role}
                        </Badge>
                      )}
                    </TableCell>
                    {isOwner && (
                      <TableCell>
                        {!isSelf && (
                          <Button variant="ghost" size="sm" onClick={() => setRemoveTarget(member)}>
                            <TrashIcon className="h-4 w-4 text-destructive" />
                          </Button>
                        )}
                      </TableCell>
                    )}
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      {isOwner && (
        <Card>
          <CardHeader>
            <CardTitle>Invite people</CardTitle>
            <CardDescription>
              No email is sent. Creating an invitation gives you a link to share;
              it expires after 7 days.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-6">
            <div className="flex items-center gap-2">
              <Input
                type="email"
                placeholder="teammate@example.com"
                value={inviteEmail}
                onChange={(e) => setInviteEmail(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && handleInvite()}
                className="max-w-sm"
              />
              <Select value={inviteRole} onValueChange={(role) => setInviteRole(role as OrganizationRole)}>
                <SelectTrigger className="w-32">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="admin">Admin</SelectItem>
                  <SelectItem value="member">Member</SelectItem>
                </SelectContent>
              </Select>
              <Button onClick={handleInvite} disabled={inviting || !inviteEmail.trim()}>
                {inviting ? 'Inviting...' : 'Invite'}
              </Button>
            </div>

            {invitations.length > 0 && (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Email</TableHead>
                    <TableHead>Role</TableHead>
                    <TableHead>Sent</TableHead>
                    <TableHead className="w-[100px]"></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {invitations.map((invitation) => (
                    <TableRow key={invitation.id}>
                      <TableCell className="font-medium">{invitation.email}</TableCell>
                      <TableCell>
                        <Badge variant="outline" className="capitalize">{invitation.role}</Badge>
                      </TableCell>
                      <TableCell className="text-muted-foreground">
                        {new Date(invitation.created_at).toLocaleDateString()}
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center gap-1">
                          {invitation.accept_url && (
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => copyAcceptUrl(invitation.accept_url!)}
                              title="Copy accept link"
                            >
                              <ClipboardIcon className="h-4 w-4" />
                            </Button>
                          )}
                          <Button variant="ghost" size="sm" onClick={() => handleRevoke(invitation)}>
                            <TrashIcon className="h-4 w-4 text-destructive" />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </CardContent>
        </Card>
      )}

      <AlertDialog open={removeTarget !== null} onOpenChange={() => setRemoveTarget(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Remove {removeTarget?.name}?</AlertDialogTitle>
            <AlertDialogDescription>
              They immediately lose access to {currentOrganization?.name} and all
              of its servers and applications.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={removing}>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleRemove}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              disabled={removing}
            >
              {removing ? 'Removing...' : 'Remove'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
