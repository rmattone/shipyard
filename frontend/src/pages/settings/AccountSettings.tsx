import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { authApi, getErrorMessage } from '../../services/api'
import { useAuth } from '@/hooks/useAuth'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
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

export function AccountSettings() {
  const { user, refreshUser } = useAuth()

  // Profile
  const [name, setName] = useState(user?.name ?? '')
  const [email, setEmail] = useState(user?.email ?? '')
  const [savingProfile, setSavingProfile] = useState(false)

  // Password
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [savingPassword, setSavingPassword] = useState(false)

  // Sessions
  const [confirmLogoutOthers, setConfirmLogoutOthers] = useState(false)
  const [loggingOutOthers, setLoggingOutOthers] = useState(false)

  useEffect(() => {
    setName(user?.name ?? '')
    setEmail(user?.email ?? '')
  }, [user?.name, user?.email])

  const profileUnchanged =
    name.trim() === (user?.name ?? '') && email.trim() === (user?.email ?? '')

  const handleSaveProfile = async () => {
    if (!name.trim() || !email.trim()) return
    setSavingProfile(true)
    try {
      await authApi.updateProfile({ name: name.trim(), email: email.trim() })
      await refreshUser()
      toast.success('Profile updated')
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to update profile'))
    } finally {
      setSavingProfile(false)
    }
  }

  const passwordsMismatch =
    password.length > 0 && passwordConfirmation.length > 0 && password !== passwordConfirmation

  const handleChangePassword = async () => {
    if (!currentPassword || !password || password !== passwordConfirmation) return
    setSavingPassword(true)
    try {
      await authApi.updatePassword({
        current_password: currentPassword,
        password,
        password_confirmation: passwordConfirmation,
      })
      setCurrentPassword('')
      setPassword('')
      setPasswordConfirmation('')
      toast.success('Password updated. Other sessions have been signed out.')
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to update password'))
    } finally {
      setSavingPassword(false)
    }
  }

  const handleLogoutOthers = async () => {
    setLoggingOutOthers(true)
    try {
      await authApi.logoutOtherSessions()
      toast.success('Other sessions have been signed out.')
      setConfirmLogoutOthers(false)
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to sign out other sessions'))
    } finally {
      setLoggingOutOthers(false)
    }
  }

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle>Profile</CardTitle>
          <CardDescription>Your name and the email you sign in with.</CardDescription>
        </CardHeader>
        <CardContent>
          <div>
            <div className="flex items-start justify-between py-6 border-b gap-6">
              <div>
                <p className="font-medium">Name</p>
                <p className="text-sm text-muted-foreground">Shown to your teammates.</p>
              </div>
              <Input
                value={name}
                onChange={(e) => setName(e.target.value)}
                maxLength={255}
                className="w-56"
              />
            </div>

            <div className="flex items-start justify-between py-6 border-b gap-6">
              <div>
                <p className="font-medium">Email</p>
                <p className="text-sm text-muted-foreground">
                  Used to sign in. Changing it takes effect immediately.
                </p>
              </div>
              <Input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                maxLength={255}
                className="w-56"
              />
            </div>

            <div className="flex justify-end pt-6">
              <Button
                onClick={handleSaveProfile}
                disabled={savingProfile || !name.trim() || !email.trim() || profileUnchanged}
              >
                {savingProfile ? 'Saving...' : 'Save'}
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Password</CardTitle>
          <CardDescription>
            Changing your password signs out every other session.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form
            className="max-w-sm space-y-4"
            onSubmit={(e) => {
              e.preventDefault()
              handleChangePassword()
            }}
          >
            <div className="space-y-2">
              <Label htmlFor="current-password">Current password</Label>
              <Input
                id="current-password"
                type="password"
                autoComplete="current-password"
                value={currentPassword}
                onChange={(e) => setCurrentPassword(e.target.value)}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="new-password">New password</Label>
              <Input
                id="new-password"
                type="password"
                autoComplete="new-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="new-password-confirmation">Confirm new password</Label>
              <Input
                id="new-password-confirmation"
                type="password"
                autoComplete="new-password"
                value={passwordConfirmation}
                onChange={(e) => setPasswordConfirmation(e.target.value)}
              />
              {passwordsMismatch && (
                <p className="text-xs text-destructive">Passwords do not match.</p>
              )}
            </div>
            <Button
              type="submit"
              disabled={
                savingPassword ||
                !currentPassword ||
                !password ||
                password !== passwordConfirmation
              }
            >
              {savingPassword ? 'Updating...' : 'Update password'}
            </Button>
          </form>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Sessions</CardTitle>
          <CardDescription>
            Sign out of every device except this one.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex items-start justify-between">
            <div>
              <p className="font-medium">Log out other sessions</p>
              <p className="text-sm text-muted-foreground">
                Revokes every login token except the one this browser is using.
              </p>
            </div>
            <Button variant="outline" onClick={() => setConfirmLogoutOthers(true)}>
              Log out other sessions
            </Button>
          </div>
        </CardContent>
      </Card>

      <AlertDialog open={confirmLogoutOthers} onOpenChange={setConfirmLogoutOthers}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Log out other sessions?</AlertDialogTitle>
            <AlertDialogDescription>
              Any other browser or device signed in to your account will be
              signed out immediately. This session stays active.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={loggingOutOthers}>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={handleLogoutOthers} disabled={loggingOutOthers}>
              {loggingOutOthers ? 'Signing out...' : 'Log out other sessions'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
