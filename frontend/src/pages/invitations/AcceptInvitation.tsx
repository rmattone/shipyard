import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { invitationsApi, getErrorMessage, OrganizationRole } from '@/services/api'
import { useAuth } from '@/hooks/useAuth'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { LoadingSpinner } from '@/components/custom'
import shipyardLogo from '@/assets/shipyard.svg'

interface InvitationDetails {
  organization: string
  email: string
  role: OrganizationRole
  expired: boolean
  existing_user: boolean
}

export default function AcceptInvitation() {
  const [searchParams] = useSearchParams()
  const token = searchParams.get('token')
  const { user, isAuthenticated, loading: authLoading, acceptInvitation } = useAuth()

  const [details, setDetails] = useState<InvitationDetails | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [accepting, setAccepting] = useState(false)

  // Registration form (new users only)
  const [name, setName] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')

  useEffect(() => {
    if (!token) {
      setLoadError('This invitation link is missing its token.')
      setLoading(false)
      return
    }

    invitationsApi.show(token)
      .then((res) => setDetails(res.data))
      .catch((error) => setLoadError(getErrorMessage(error, 'This invitation does not exist or was revoked.')))
      .finally(() => setLoading(false))
  }, [token])

  const handleAccept = async () => {
    if (!token) return
    setAccepting(true)
    try {
      const needsRegistration = !details?.existing_user
      await acceptInvitation(
        token,
        needsRegistration
          ? { name: name.trim(), password, password_confirmation: passwordConfirmation }
          : undefined
      )
      toast.success(`Welcome to ${details?.organization}`)
      // Full reload so all org-scoped contexts boot inside the new org.
      window.location.assign('/app/')
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to accept the invitation'))
      setAccepting(false)
    }
  }

  const renderBody = () => {
    if (loading || authLoading) {
      return (
        <div className="flex justify-center py-8">
          <LoadingSpinner size="lg" />
        </div>
      )
    }

    if (loadError || !details) {
      return <p className="text-sm text-muted-foreground">{loadError}</p>
    }

    if (details.expired) {
      return (
        <p className="text-sm text-muted-foreground">
          This invitation has expired. Ask the organization owner to send a new one.
        </p>
      )
    }

    // The invited email already has an account.
    if (details.existing_user) {
      if (!isAuthenticated) {
        return (
          <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
              This invitation belongs to <span className="font-medium">{details.email}</span>,
              which already has an account. Log in first, then open this link again.
            </p>
            <Button asChild className="w-full">
              <Link to="/login">Go to login</Link>
            </Button>
          </div>
        )
      }

      if (user?.email !== details.email) {
        return (
          <p className="text-sm text-muted-foreground">
            This invitation was sent to <span className="font-medium">{details.email}</span>,
            but you are logged in as <span className="font-medium">{user?.email}</span>.
            Log out and sign in with the invited account to accept it.
          </p>
        )
      }

      return (
        <Button className="w-full" onClick={handleAccept} disabled={accepting}>
          {accepting ? 'Joining...' : `Join ${details.organization}`}
        </Button>
      )
    }

    // New account: register through the invitation.
    return (
      <div className="space-y-4">
        <div className="space-y-2">
          <Label htmlFor="invite-name">Your name</Label>
          <Input id="invite-name" value={name} onChange={(e) => setName(e.target.value)} />
        </div>
        <div className="space-y-2">
          <Label htmlFor="invite-password">Password</Label>
          <Input
            id="invite-password"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor="invite-password-confirmation">Confirm password</Label>
          <Input
            id="invite-password-confirmation"
            type="password"
            value={passwordConfirmation}
            onChange={(e) => setPasswordConfirmation(e.target.value)}
          />
        </div>
        <Button
          className="w-full"
          onClick={handleAccept}
          disabled={accepting || !name.trim() || !password || password !== passwordConfirmation}
        >
          {accepting ? 'Creating account...' : `Create account & join ${details.organization}`}
        </Button>
        {password !== '' && passwordConfirmation !== '' && password !== passwordConfirmation && (
          <p className="text-xs text-destructive">Passwords do not match.</p>
        )}
      </div>
    )
  }

  return (
    <div className="min-h-screen flex items-center justify-center px-4">
      <Card className="w-full max-w-md">
        <CardHeader className="text-center">
          <div className="mx-auto mb-2 h-10 w-10 rounded-md bg-slate-800 dark:bg-white p-2">
            <img src={shipyardLogo} alt="ShipYard" className="h-full w-full object-contain invert dark:invert-0" />
          </div>
          <CardTitle>
            {details && !loadError ? `Join ${details.organization}` : 'Organization invitation'}
          </CardTitle>
          {details && !loadError && !details.expired && (
            <CardDescription>
              You've been invited as {details.role === 'admin' ? 'an admin' : 'a member'} ({details.email}).
            </CardDescription>
          )}
        </CardHeader>
        <CardContent>{renderBody()}</CardContent>
      </Card>
    </div>
  )
}
