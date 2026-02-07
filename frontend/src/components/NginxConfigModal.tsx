import { useState, useEffect } from 'react'
import { toast } from 'sonner'
import { nginxApi } from '../services/api'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { LoadingSpinner } from '@/components/custom'

interface NginxConfigModalProps {
  appId: number
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function NginxConfigModal({ appId, open, onOpenChange }: NginxConfigModalProps) {
  const [content, setContent] = useState('')
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (open) {
      loadConfig()
    }
  }, [open, appId])

  const loadConfig = async () => {
    setLoading(true)
    try {
      const response = await nginxApi.getConfig(appId)
      setContent(response.data.content)
    } catch {
      toast.error('Failed to load nginx configuration')
    } finally {
      setLoading(false)
    }
  }

  const handleSave = async () => {
    setSaving(true)
    try {
      await nginxApi.updateConfig(appId, content)
      toast.success('Nginx configuration updated successfully')
      onOpenChange(false)
    } catch (error: unknown) {
      const message = error instanceof Error ? error.message : 'Failed to update nginx configuration'
      const axiosError = error as { response?: { data?: { message?: string } } }
      toast.error(axiosError.response?.data?.message || message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-4xl max-h-[90vh]">
        <DialogHeader>
          <DialogTitle>Edit Nginx Configuration</DialogTitle>
          <DialogDescription>
            Edit the nginx configuration for this application. Changes will be applied immediately.
          </DialogDescription>
        </DialogHeader>

        {loading ? (
          <div className="flex items-center justify-center py-12">
            <LoadingSpinner size="lg" />
          </div>
        ) : (
          <div className="space-y-4">
            <Textarea
              value={content}
              onChange={(e) => setContent(e.target.value)}
              className="font-mono text-sm h-[400px] resize-none"
              placeholder="# Nginx configuration..."
            />
            <p className="text-xs text-muted-foreground">
              Warning: Invalid configuration may cause the server to fail. Make sure your nginx syntax is correct.
            </p>
          </div>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
            Cancel
          </Button>
          <Button onClick={handleSave} disabled={loading || saving}>
            {saving ? (
              <>
                <LoadingSpinner size="sm" className="mr-2" />
                Saving...
              </>
            ) : (
              'Save Configuration'
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
