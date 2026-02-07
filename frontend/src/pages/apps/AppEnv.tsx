import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { envApi, applicationsApi, Application } from '../../services/api'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { LoadingSpinner } from '@/components/custom'
import { EyeIcon, EyeSlashIcon } from '@heroicons/react/24/outline'

export default function AppEnv() {
  const { id } = useParams<{ id: string }>()
  const [app, setApp] = useState<Application | null>(null)
  const [content, setContent] = useState('')
  const [originalContent, setOriginalContent] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [revealed, setRevealed] = useState(false)

  const appId = parseInt(id || '0')

  useEffect(() => {
    if (appId) {
      Promise.all([
        applicationsApi.get(appId),
        envApi.getContent(appId),
      ])
        .then(([appRes, envRes]) => {
          setApp(appRes.data)
          setContent(envRes.data.content || '')
          setOriginalContent(envRes.data.content || '')
        })
        .catch(() => {
          // If the env-file endpoint doesn't exist yet, just load the app
          applicationsApi.get(appId).then((appRes) => {
            setApp(appRes.data)
          })
        })
        .finally(() => setLoading(false))
    }
  }, [appId])

  const handleSave = async () => {
    setSaving(true)
    try {
      await envApi.updateContent(appId, content)
      setOriginalContent(content)
      toast.success('Environment variables saved')
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to save environment variables')
    } finally {
      setSaving(false)
    }
  }

  const hasChanges = content !== originalContent

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Environment</h1>
        <p className="text-muted-foreground">
          Manage the environment variables for {app?.name}
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Environment</CardTitle>
          <CardDescription>
            Below you may edit the <code className="bg-muted px-1.5 py-0.5 rounded text-sm font-mono">.env</code> file for your application, which is a standard default environment file that typically loaded by applications. If the application is uninstalled, the environment file will also be removed.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <Label>Environment variables</Label>
            </div>
            <p className="text-sm text-muted-foreground">
              Your application's environment variables.
            </p>
          </div>

          <div className="relative">
            <Textarea
              value={content}
              onChange={(e) => setContent(e.target.value)}
              placeholder={`APP_NAME=MyApp\nAPP_ENV=production\nAPP_KEY=\nAPP_DEBUG=false\nAPP_URL=https://example.com\n\nDB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=myapp\nDB_USERNAME=root\nDB_PASSWORD=`}
              className={`font-mono text-sm min-h-[400px] resize-y ${!revealed ? 'blur-sm select-none' : ''}`}
              readOnly={!revealed}
            />

            {!revealed && (
              <div className="absolute inset-0 flex flex-col items-center justify-center bg-background/50 rounded-md">
                <p className="text-sm text-muted-foreground mb-4">
                  Environment variables should not be shared publicly.
                </p>
                <Button onClick={() => setRevealed(true)}>
                  <EyeIcon className="h-4 w-4 mr-2" />
                  Reveal
                </Button>
              </div>
            )}
          </div>

          {revealed && (
            <div className="flex items-center justify-between">
              <Button
                variant="outline"
                size="sm"
                onClick={() => setRevealed(false)}
              >
                <EyeSlashIcon className="h-4 w-4 mr-2" />
                Hide
              </Button>

              <div className="flex items-center gap-4">
                {hasChanges && (
                  <span className="text-sm text-muted-foreground">Unsaved changes</span>
                )}
                <Button onClick={handleSave} disabled={saving || !hasChanges}>
                  {saving && <LoadingSpinner size="sm" className="mr-2" />}
                  Save
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      <Card className="border-yellow-200 bg-yellow-50 dark:bg-yellow-950/20 dark:border-yellow-900">
        <CardContent className="pt-6">
          <p className="text-sm text-yellow-800 dark:text-yellow-200">
            <strong>Security Notice:</strong> Environment variables are encrypted at rest and only decrypted during deployment. Never commit sensitive values to your repository.
          </p>
        </CardContent>
      </Card>
    </div>
  )
}
