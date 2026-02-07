import { useEffect, useState, useRef, useCallback, useMemo } from 'react'
import { useParams } from 'react-router-dom'
import { logsApi, applicationsApi, Application, LogFile, LogContent } from '../../services/api'
import { toast } from 'sonner'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { LoadingSpinner } from '@/components/custom'
import {
  ArrowPathIcon,
  MagnifyingGlassIcon,
  DocumentTextIcon,
} from '@heroicons/react/24/outline'

// Escape special regex characters
function escapeRegExp(string: string): string {
  return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

// Component to render log content with highlighted search matches
function HighlightedContent({ content, search }: { content: string; search: string }) {
  const parts = useMemo(() => {
    if (!search) {
      return [{ text: content, highlight: false }]
    }

    const regex = new RegExp(`(${escapeRegExp(search)})`, 'gi')
    const segments: { text: string; highlight: boolean }[] = []
    let lastIndex = 0
    let match

    while ((match = regex.exec(content)) !== null) {
      // Add text before match
      if (match.index > lastIndex) {
        segments.push({ text: content.slice(lastIndex, match.index), highlight: false })
      }
      // Add matched text
      segments.push({ text: match[1], highlight: true })
      lastIndex = regex.lastIndex
    }

    // Add remaining text
    if (lastIndex < content.length) {
      segments.push({ text: content.slice(lastIndex), highlight: false })
    }

    return segments.length > 0 ? segments : [{ text: content, highlight: false }]
  }, [content, search])

  return (
    <>
      {parts.map((part, index) =>
        part.highlight ? (
          <mark
            key={index}
            className="bg-yellow-400 text-gray-900 px-0.5 rounded-sm"
          >
            {part.text}
          </mark>
        ) : (
          <span key={index}>{part.text}</span>
        )
      )}
    </>
  )
}

const AUTO_REFRESH_OPTIONS = [
  { value: '0', label: 'Off' },
  { value: '5000', label: '5s' },
  { value: '10000', label: '10s' },
  { value: '30000', label: '30s' },
  { value: '60000', label: '1m' },
  { value: '300000', label: '5m' },
]

const LINES_OPTIONS = [
  { value: '100', label: '100 lines' },
  { value: '500', label: '500 lines' },
  { value: '1000', label: '1000 lines' },
  { value: '2500', label: '2500 lines' },
  { value: '5000', label: '5000 lines' },
]

export default function AppLogs() {
  const { id } = useParams<{ id: string }>()
  const [app, setApp] = useState<Application | null>(null)
  const [logFiles, setLogFiles] = useState<LogFile[]>([])
  const [selectedFile, setSelectedFile] = useState<string>('')
  const [logContent, setLogContent] = useState<LogContent | null>(null)
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [search, setSearch] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [autoRefresh, setAutoRefresh] = useState('0')
  const [lines, setLines] = useState('500')
  const logRef = useRef<HTMLPreElement>(null)
  const autoRefreshIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null)

  const appId = parseInt(id || '0')

  const fetchLogContent = useCallback(async (showLoading = true) => {
    if (!selectedFile) return

    if (showLoading) {
      setRefreshing(true)
    }

    try {
      const res = await logsApi.getContent(
        appId,
        selectedFile,
        parseInt(lines),
        search || undefined
      )
      setLogContent(res.data)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to fetch log content')
    } finally {
      setRefreshing(false)
    }
  }, [appId, selectedFile, lines, search])

  // Initial load
  useEffect(() => {
    if (appId) {
      Promise.all([
        applicationsApi.get(appId),
        logsApi.listFiles(appId),
      ])
        .then(([appRes, logsRes]) => {
          setApp(appRes.data)
          setLogFiles(logsRes.data.files)
          // Auto-select laravel.log if available, otherwise first file
          const laravelLog = logsRes.data.files.find(f => f.name === 'laravel.log')
          if (laravelLog) {
            setSelectedFile(laravelLog.name)
          } else if (logsRes.data.files.length > 0) {
            setSelectedFile(logsRes.data.files[0].name)
          }
        })
        .catch((error: unknown) => {
          const err = error as { response?: { data?: { message?: string } } }
          toast.error(err.response?.data?.message || 'Failed to load log files')
        })
        .finally(() => setLoading(false))
    }
  }, [appId])

  // Fetch log content when file, lines, or search changes
  useEffect(() => {
    if (selectedFile) {
      fetchLogContent()
    }
  }, [selectedFile, lines, search, fetchLogContent])

  // Auto-refresh interval
  useEffect(() => {
    if (autoRefreshIntervalRef.current) {
      clearInterval(autoRefreshIntervalRef.current)
      autoRefreshIntervalRef.current = null
    }

    const interval = parseInt(autoRefresh)
    if (interval > 0 && selectedFile) {
      autoRefreshIntervalRef.current = setInterval(() => {
        fetchLogContent(false)
      }, interval)
    }

    return () => {
      if (autoRefreshIntervalRef.current) {
        clearInterval(autoRefreshIntervalRef.current)
      }
    }
  }, [autoRefresh, selectedFile, fetchLogContent])

  // Scroll to bottom when content updates
  useEffect(() => {
    if (logRef.current) {
      logRef.current.scrollTop = logRef.current.scrollHeight
    }
  }, [logContent?.content])

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault()
    setSearch(searchInput)
  }

  const handleRefresh = () => {
    fetchLogContent()
  }

  const formatFileSize = (bytes: number): string => {
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  }

  // Count matches for the current search term
  const matchCount = useMemo(() => {
    if (!search || !logContent?.content) return 0
    const regex = new RegExp(escapeRegExp(search), 'gi')
    const matches = logContent.content.match(regex)
    return matches ? matches.length : 0
  }, [search, logContent?.content])

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
        <h1 className="text-2xl font-bold">Logs</h1>
        <p className="text-muted-foreground">
          View application logs for {app?.name}
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <DocumentTextIcon className="h-5 w-5" />
            Log Viewer
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {/* Controls */}
          <div className="flex flex-wrap items-end gap-4">
            {/* File selector */}
            <div className="space-y-2">
              <Label>Log File</Label>
              <Select value={selectedFile} onValueChange={setSelectedFile}>
                <SelectTrigger className="w-[200px]">
                  <SelectValue placeholder="Select a log file" />
                </SelectTrigger>
                <SelectContent>
                  {logFiles.length === 0 ? (
                    <SelectItem value="_none" disabled>
                      No log files found
                    </SelectItem>
                  ) : (
                    logFiles.map((file) => (
                      <SelectItem key={file.name} value={file.name}>
                        {file.name}
                      </SelectItem>
                    ))
                  )}
                </SelectContent>
              </Select>
            </div>

            {/* Lines selector */}
            <div className="space-y-2">
              <Label>Lines</Label>
              <Select value={lines} onValueChange={setLines}>
                <SelectTrigger className="w-[140px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {LINES_OPTIONS.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* Auto-refresh selector */}
            <div className="space-y-2">
              <Label>Auto-refresh</Label>
              <Select value={autoRefresh} onValueChange={setAutoRefresh}>
                <SelectTrigger className="w-[100px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {AUTO_REFRESH_OPTIONS.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* Search */}
            <form onSubmit={handleSearch} className="flex-1 min-w-[200px] space-y-2">
              <Label>Search</Label>
              <div className="flex gap-2">
                <div className="relative flex-1">
                  <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                  <Input
                    value={searchInput}
                    onChange={(e) => setSearchInput(e.target.value)}
                    placeholder="Filter logs..."
                    className="pl-9"
                  />
                </div>
                <Button type="submit" variant="secondary">
                  Search
                </Button>
                {search && (
                  <Button
                    type="button"
                    variant="ghost"
                    onClick={() => {
                      setSearch('')
                      setSearchInput('')
                    }}
                  >
                    Clear
                  </Button>
                )}
              </div>
            </form>

            {/* Refresh button */}
            <div className="space-y-2">
              <Label className="invisible">Refresh</Label>
              <Button
                onClick={handleRefresh}
                variant="outline"
                disabled={refreshing || !selectedFile}
              >
                <ArrowPathIcon className={`h-4 w-4 mr-2 ${refreshing ? 'animate-spin' : ''}`} />
                Refresh
              </Button>
            </div>
          </div>

          {/* Log stats */}
          {logContent && (
            <div className="flex flex-wrap items-center gap-4 text-sm text-muted-foreground">
              <span>
                Showing {logContent.returned_lines.toLocaleString()} of {logContent.total_lines.toLocaleString()} lines
              </span>
              <span>|</span>
              <span>File size: {formatFileSize(logContent.file_size)}</span>
              {search && (
                <>
                  <span>|</span>
                  <span className="flex items-center gap-1.5">
                    <span className="inline-flex items-center rounded-md bg-yellow-400/20 px-2 py-0.5 text-xs font-medium text-yellow-600 dark:text-yellow-400 ring-1 ring-inset ring-yellow-400/30">
                      {matchCount.toLocaleString()} {matchCount === 1 ? 'match' : 'matches'}
                    </span>
                    <span>for "{search}"</span>
                  </span>
                </>
              )}
              {autoRefresh !== '0' && (
                <>
                  <span>|</span>
                  <span className="flex items-center gap-1">
                    <span className="relative flex h-2 w-2">
                      <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                      <span className="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                    </span>
                    Auto-refreshing every {AUTO_REFRESH_OPTIONS.find(o => o.value === autoRefresh)?.label}
                  </span>
                </>
              )}
            </div>
          )}

          {/* Log content */}
          <pre
            ref={logRef}
            className="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto text-xs font-mono h-[600px] overflow-y-auto whitespace-pre-wrap"
          >
            {!selectedFile ? (
              <span className="text-gray-500">
                No log file selected. Please select a log file from the dropdown above.
              </span>
            ) : refreshing && !logContent ? (
              <span className="text-gray-500">Loading logs...</span>
            ) : !logContent?.content ? (
              <span className="text-gray-500">
                {search
                  ? `No log entries matching "${search}"`
                  : 'No log content available.'}
              </span>
            ) : (
              <HighlightedContent content={logContent.content} search={search} />
            )}
          </pre>
        </CardContent>
      </Card>

      {/* Info card */}
      <Card className="border-blue-200 bg-blue-50 dark:bg-blue-950/20 dark:border-blue-900">
        <CardContent className="pt-6">
          <p className="text-sm text-blue-800 dark:text-blue-200">
            <strong>Tip:</strong> Log files are fetched from the server's storage/logs directory using SSH. Use the search feature to filter logs server-side for better performance with large files.
          </p>
        </CardContent>
      </Card>
    </div>
  )
}
