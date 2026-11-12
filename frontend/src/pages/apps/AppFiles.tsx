import { useCallback, useEffect, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { AlertTriangle, ArrowUp, CheckCircle2, ChevronRight, Copy, File, Folder, FolderPlus, LockKeyhole, RefreshCw, Upload, X } from 'lucide-react'
import { toast } from 'sonner'
import { applicationFilesApi, ApplicationDirectory, ApplicationFileUpload, getErrorMessage } from '@/services/api'
import { useAuth } from '@/hooks/useAuth'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog'
import { AlertDialog, AlertDialogContent, AlertDialogHeader, AlertDialogTitle, AlertDialogDescription, AlertDialogFooter, AlertDialogCancel, AlertDialogAction } from '@/components/ui/alert-dialog'
import { LoadingSpinner } from '@/components/custom'
import { cn } from '@/lib/utils'

function sizeLabel(bytes: number) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KiB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MiB`
}

function FolderBadges({ public: isPublic, ephemeral }: { public: boolean; ephemeral: boolean }) {
  return <span className="flex flex-wrap gap-1">
    {isPublic && <Badge variant="outline" className="text-amber-700 dark:text-amber-400">Web-accessible</Badge>}
    {ephemeral && <Badge variant="outline">Release folder</Badge>}
  </span>
}

export default function AppFiles() {
  const appId = Number(useParams<{ id: string }>().id)
  const { hasRole } = useAuth()
  const canWrite = hasRole('owner', 'admin')
  const [directory, setDirectory] = useState<ApplicationDirectory | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [history, setHistory] = useState<ApplicationFileUpload[]>([])
  const [historyError, setHistoryError] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [uploading, setUploading] = useState(false)
  const [progress, setProgress] = useState(0)
  const [allowPublic, setAllowPublic] = useState(false)
  const [dragging, setDragging] = useState(false)
  const [replaceOpen, setReplaceOpen] = useState(false)
  const [folderOpen, setFolderOpen] = useState(false)
  const [folderName, setFolderName] = useState('')
  const [creating, setCreating] = useState(false)
  const [folderError, setFolderError] = useState('')
  const [lastUpload, setLastUpload] = useState<ApplicationFileUpload | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)
  const requestId = useRef(0)
  const requestedPath = useRef<string | undefined>()
  const busy = loading || uploading || creating

  const loadDirectory = useCallback(async (path?: string) => {
    const request = ++requestId.current
    requestedPath.current = path
    setLoading(true)
    setError('')
    setAllowPublic(false)
    try {
      const response = await applicationFilesApi.directories(appId, path)
      if (request === requestId.current) setDirectory(response.data)
    } catch (err) {
      if (request === requestId.current) setError(getErrorMessage(err, 'Could not load folders. Try again.'))
    } finally {
      if (request === requestId.current) setLoading(false)
    }
  }, [appId])

  const loadHistory = useCallback(async () => {
    try {
      const response = await applicationFilesApi.history(appId)
      setHistory(response.data.data)
      setHistoryError('')
    } catch (err) {
      setHistoryError(getErrorMessage(err, 'Could not load recent uploads.'))
    }
  }, [appId])

  useEffect(() => {
    setDirectory(null)
    setFile(null)
    setLastUpload(null)
    setHistory([])
    void loadDirectory()
    void loadHistory()
    return () => { requestId.current++ }
  }, [loadDirectory, loadHistory])

  useEffect(() => {
    if (!uploading) return
    const preventLeave = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', preventLeave)
    return () => window.removeEventListener('beforeunload', preventLeave)
  }, [uploading])

  const selectFile = (files: FileList | null) => {
    if (!files?.length) return
    if (files.length !== 1) { toast.error('Choose one file at a time.'); return }
    if (files[0].size > (directory?.max_upload_bytes ?? 100 * 1024 * 1024)) {
      toast.error('The maximum file size is 100 MiB.')
      return
    }
    setFile(files[0])
    setLastUpload(null)
  }

  const copyPath = async (path: string) => {
    try { await navigator.clipboard.writeText(path); toast.success('Path copied') }
    catch { toast.error('Could not copy. Select and copy the path manually.') }
  }

  const upload = async (overwrite = false) => {
    if (!directory || !file || busy || !canWrite) return
    setUploading(true)
    setProgress(0)
    setLastUpload(null)
    setReplaceOpen(false)
    try {
      const response = await applicationFilesApi.upload(appId, directory.path, file, overwrite, allowPublic, setProgress)
      setLastUpload(response.data.data)
      setFile(null)
      if (fileInput.current) fileInput.current.value = ''
      toast.success('File uploaded to the server')
      void loadHistory()
      void loadDirectory(directory.path)
    } catch (err) {
      if ((err as { response?: { status: number } }).response?.status === 409) setReplaceOpen(true)
      else toast.error(getErrorMessage(err, 'Upload failed. Check the connection and try again.'))
    } finally {
      setUploading(false)
    }
  }

  const createFolder = async (event: React.FormEvent) => {
    event.preventDefault()
    if (!directory || !folderName.trim() || creating) return
    setCreating(true)
    setFolderError('')
    try {
      await applicationFilesApi.createDirectory(appId, directory.path, folderName.trim(), allowPublic)
      setFolderOpen(false)
      toast.success('Folder created')
      void loadDirectory([directory.path, folderName.trim()].filter(Boolean).join('/'))
    } catch (err) {
      setFolderError(getErrorMessage(err, 'Could not create folder.'))
    } finally {
      setCreating(false)
    }
  }

  const pathParts = directory?.path.split('/').filter(Boolean) ?? []
  const destination = directory ? `${directory.absolute_path}${file ? `/${file.name}` : ''}` : ''
  const publicConfirmed = !directory?.public || allowPublic
  const canUpload = canWrite && !!file && !!directory && !busy && !error && publicConfirmed && (directory.exists || directory.path === directory.default_path)

  return <div className="space-y-6">
    <div>
      <h1 className="text-2xl font-semibold tracking-tight">Files</h1>
      <p className="mt-1 text-sm text-muted-foreground">Upload a file directly to this application. Choose where it belongs on the server.</p>
    </div>
    {!canWrite && <p className="rounded-lg border bg-muted/30 p-4 text-sm">You can browse folders and recent uploads. An organization owner or admin can upload files and create folders.</p>}
    <div className="grid items-start gap-6 lg:grid-cols-2">
      <Card>
        <CardHeader className="flex flex-row items-center justify-between gap-3 space-y-0">
          <CardTitle className="text-base">Destination folder</CardTitle>
          <Button variant="ghost" size="icon" aria-label="Refresh folders" disabled={busy} onClick={() => void loadDirectory(requestedPath.current)}><RefreshCw className="h-4 w-4" /></Button>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" size="sm" disabled={busy} onClick={() => void loadDirectory()}><LockKeyhole className="mr-2 h-4 w-4" />Private storage</Button>
            <Button variant="outline" size="sm" disabled={busy} onClick={() => void loadDirectory('')}>Application root</Button>
            {canWrite && <Button variant="outline" size="sm" disabled={busy || !directory || !!error || !publicConfirmed} onClick={() => { setFolderName(''); setFolderError(''); setFolderOpen(true) }}><FolderPlus className="mr-2 h-4 w-4" />New folder</Button>}
          </div>
          {error && <div role="alert" className="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">{error} <button className="underline" onClick={() => void loadDirectory(requestedPath.current)}>Retry</button></div>}
          {directory && <nav aria-label="Folder breadcrumb" className="flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
            <button disabled={busy} className="rounded px-1 py-1 hover:text-foreground" onClick={() => void loadDirectory('')}>Application</button>
            {pathParts.map((part, i) => <span key={i} className="inline-flex min-w-0 items-center gap-1"><ChevronRight className="h-3 w-3 shrink-0" /><button disabled={busy} className="break-all rounded px-1 py-1 hover:text-foreground" aria-current={i === pathParts.length - 1 ? 'location' : undefined} onClick={() => void loadDirectory(pathParts.slice(0, i + 1).join('/'))}>{part}</button></span>)}
          </nav>}
          <div className="min-h-48 overflow-hidden rounded-lg border" aria-busy={loading}>
            {loading ? <div className="flex min-h-48 items-center justify-center"><LoadingSpinner /></div> : directory && <>
              {directory.path && <button disabled={busy} onClick={() => void loadDirectory(pathParts.slice(0, -1).join('/'))} className="flex w-full items-center gap-3 border-b px-4 py-3 text-left text-sm text-muted-foreground hover:bg-muted/50"><ArrowUp className="h-4 w-4" />Parent folder</button>}
              <div className="max-h-80 overflow-y-auto divide-y">
                {directory.folders.map(folder => <button key={folder.path} disabled={busy} onClick={() => void loadDirectory(folder.path)} className="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-muted/50 disabled:opacity-50">
                  <Folder className="h-4 w-4 shrink-0 text-muted-foreground" />
                  <span className="min-w-0 flex-1 space-y-1"><span className="block break-all text-sm">{folder.name}</span><FolderBadges {...folder} /></span>
                  <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                </button>)}
              </div>
              {directory.folders.length === 0 && <p className="px-4 py-8 text-center text-sm text-muted-foreground">{directory.exists ? 'No subfolders. You can upload here or create a folder.' : directory.path === directory.default_path ? 'This private folder will be created with your first upload.' : 'This folder no longer exists. Choose another destination.'}</p>}
              {directory.truncated && <p className="p-3 text-xs text-muted-foreground">Showing the first 1,000 folders.</p>}
            </>}
          </div>
          {directory && <div className="space-y-2">
            <FolderBadges {...directory} />
            {directory.ephemeral && <p className="flex gap-2 text-sm text-amber-700 dark:text-amber-400"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />This is a release folder. Files here may be replaced or removed by a deployment. Use private storage to keep files across releases.</p>}
            {directory.public && <div className="rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-sm">
              <p>This folder may be accessible from the web. Choose private storage for confidential files.</p>
              {canWrite && <label className="mt-3 flex items-start gap-2"><input type="checkbox" className="mt-1" checked={allowPublic} disabled={busy} onChange={event => setAllowPublic(event.target.checked)} /><span>I understand files in this folder may be public.</span></label>}
            </div>}
            {directory.path === directory.default_path && !directory.public && <p className="flex gap-2 text-xs text-muted-foreground"><LockKeyhole className="h-4 w-4 shrink-0" />Outside the standard public folder. Available across atomic releases; custom deployment scripts and application routes can affect these files.</p>}
          </div>}
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle className="text-base">Upload file</CardTitle></CardHeader>
        <CardContent className="space-y-5">
          <input ref={fileInput} type="file" className="sr-only" id="application-upload" disabled={busy || !canWrite} onChange={event => selectFile(event.target.files)} />
          <button type="button" disabled={busy || !canWrite} onClick={() => { if (fileInput.current) { fileInput.current.value = ''; fileInput.current.click() } }}
            onDragOver={event => { event.preventDefault(); if (!busy && canWrite) setDragging(true) }} onDragLeave={() => setDragging(false)}
            onDrop={event => { event.preventDefault(); setDragging(false); if (!busy && canWrite) selectFile(event.dataTransfer.files) }}
            className={cn('flex min-h-44 w-full flex-col items-center justify-center gap-3 rounded-lg border-2 border-dashed p-6 text-center transition-colors hover:border-primary/50 hover:bg-muted/30 disabled:cursor-not-allowed disabled:opacity-50', dragging && 'border-primary bg-primary/5')}>
            <Upload className="h-7 w-7 text-muted-foreground" />
            <span className="text-sm font-medium">Drop a file here, or browse</span>
            <span className="text-xs text-muted-foreground">One file at a time · Up to 100 MiB</span>
          </button>
          {file && <div className="flex items-center gap-3 rounded-lg border p-3">
            <File className="h-5 w-5 shrink-0 text-muted-foreground" /><div className="min-w-0 flex-1"><p className="break-all text-sm font-medium">{file.name}</p><p className="text-xs text-muted-foreground">{sizeLabel(file.size)}</p></div>
            <Button size="icon" variant="ghost" aria-label="Remove selected file" disabled={uploading} onClick={() => setFile(null)}><X className="h-4 w-4" /></Button>
          </div>}
          <div className="space-y-2"><p className="text-xs font-medium text-muted-foreground">Upload destination</p><p className="min-h-12 break-all rounded-md bg-muted/50 p-3 font-mono text-xs select-all">{destination || 'Choose a folder to continue'}</p></div>
          {uploading && <div role="status" className="space-y-2"><div role="progressbar" aria-label="File upload" aria-valuemin={0} aria-valuemax={100} aria-valuenow={progress} className="h-2 overflow-hidden rounded bg-muted"><div className="h-full bg-primary transition-all" style={{ width: `${progress}%` }} /></div><p className="text-xs text-muted-foreground">{progress < 100 ? `Uploading to Shipyard… ${progress}%` : 'Transferring to the application server…'}</p></div>}
          <Button className="w-full" disabled={!canUpload} onClick={() => void upload()}>{uploading ? <LoadingSpinner size="sm" /> : <Upload className="mr-2 h-4 w-4" />}{uploading ? 'Uploading…' : 'Upload to this folder'}</Button>
          {lastUpload && <div role="status" className="space-y-3 rounded-lg border border-emerald-500/30 bg-emerald-500/5 p-4"><p className="flex items-center gap-2 text-sm font-medium"><CheckCircle2 className="h-4 w-4 text-emerald-600" />Uploaded to the server</p><p className="break-all font-mono text-xs select-all">{lastUpload.path}</p><Button variant="outline" size="sm" onClick={() => void copyPath(lastUpload.path)}><Copy className="mr-2 h-4 w-4" />Copy path</Button></div>}
        </CardContent>
      </Card>
    </div>

    <Card>
      <CardHeader className="flex flex-row items-center justify-between space-y-0"><div><CardTitle className="text-base">Recent uploads</CardTitle><p className="mt-1 text-xs text-muted-foreground">The last 20 completed transfers. Files may have changed since they were uploaded.</p></div><Button variant="ghost" size="icon" aria-label="Refresh recent uploads" onClick={() => void loadHistory()}><RefreshCw className="h-4 w-4" /></Button></CardHeader>
      <CardContent>
        {historyError && <p role="alert" className="mb-3 text-sm text-destructive">{historyError}</p>}
        {!history.length ? <p className="py-6 text-center text-sm text-muted-foreground">No uploads yet.</p> : <div className="divide-y">{history.map(item => <div key={item.id} className="flex items-start gap-3 py-4"><File className="mt-1 h-4 w-4 shrink-0 text-muted-foreground" /><div className="min-w-0 flex-1 space-y-1"><p className="break-all text-sm font-medium">{item.filename}</p><p className="break-all font-mono text-xs text-muted-foreground select-all">{item.path}</p><p className="text-xs text-muted-foreground">{sizeLabel(item.size_bytes)} · {item.user?.name ?? 'Former member'} · {new Date(item.created_at).toLocaleString()}</p></div><Button variant="ghost" size="icon" aria-label={`Copy path for ${item.filename}`} onClick={() => void copyPath(item.path)}><Copy className="h-4 w-4" /></Button></div>)}</div>}
      </CardContent>
    </Card>

    <Dialog open={folderOpen} onOpenChange={open => { if (!creating) setFolderOpen(open) }}><DialogContent><form onSubmit={createFolder} className="space-y-4"><DialogHeader><DialogTitle>New folder</DialogTitle><DialogDescription className="break-all">Create a folder inside {directory?.absolute_path}.</DialogDescription></DialogHeader><div className="space-y-2"><Label htmlFor="folder-name">Folder name</Label><Input autoFocus id="folder-name" value={folderName} onChange={event => setFolderName(event.target.value)} maxLength={255} disabled={creating} placeholder="imports" required /></div>{folderError && <p role="alert" className="text-sm text-destructive">{folderError}</p>}<DialogFooter><Button type="button" variant="outline" disabled={creating} onClick={() => setFolderOpen(false)}>Cancel</Button><Button type="submit" disabled={creating || !folderName.trim()}>{creating ? 'Creating…' : 'Create folder'}</Button></DialogFooter></form></DialogContent></Dialog>
    <AlertDialog open={replaceOpen} onOpenChange={setReplaceOpen}><AlertDialogContent><AlertDialogHeader><AlertDialogTitle>Replace existing file?</AlertDialogTitle><AlertDialogDescription className="break-all">A file already exists at {destination}. Replacing it will overwrite its contents.</AlertDialogDescription></AlertDialogHeader><AlertDialogFooter><AlertDialogCancel>Cancel</AlertDialogCancel><AlertDialogAction onClick={() => void upload(true)}>Replace file</AlertDialogAction></AlertDialogFooter></AlertDialogContent></AlertDialog>
  </div>
}
