import axios from 'axios'

const api = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  withCredentials: true,
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  // Get XSRF token from cookie
  const xsrfToken = document.cookie
    .split('; ')
    .find(row => row.startsWith('XSRF-TOKEN='))
    ?.split('=')[1]
  if (xsrfToken) {
    config.headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrfToken)
  }
  return config
})

// Function to get CSRF cookie
export const getCsrfCookie = () => axios.get('/sanctum/csrf-cookie', { withCredentials: true })

// Pulls the backend's `{ message }` out of a failed request, falling back to
// a caller-supplied message when the response is missing or unshaped (e.g. a
// network error, or a non-JSON 5xx from something in front of the app).
export const getErrorMessage = (error: unknown, fallback: string): string => {
  const err = error as { response?: { data?: { message?: string } } }
  return err.response?.data?.message || fallback
}

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    // 419: the session behind the XSRF cookie expired while the bearer token
    // stayed valid (tokens outlive sessions). Refresh the cookie and retry
    // the request once; the request interceptor picks up the new token.
    const config = error.config as (typeof error.config & { _csrfRetried?: boolean }) | undefined
    if (error.response?.status === 419 && config && !config._csrfRetried) {
      config._csrfRetried = true
      await getCsrfCookie()
      return api.request(config)
    }

    if (error.response?.status === 401) {
      // A 401 from the login request itself means wrong credentials; a hard
      // redirect here would reload the page and wipe the error message.
      const requestUrl: string = error.config?.url ?? ''
      const isLoginRequest = requestUrl.includes('/auth/login')
      const isOnLoginPage = window.location.pathname.endsWith('/login')
      // The invitation accept page is public: a visitor with a stale token
      // must not be bounced to login (they would lose the token param).
      const isOnInvitePage = window.location.pathname.includes('/invitations/accept')

      if (!isLoginRequest) {
        localStorage.removeItem('token')
        localStorage.removeItem('user')
      }
      if (!isLoginRequest && !isOnLoginPage && !isOnInvitePage) {
        window.location.href = '/app/login'
      }
    }
    return Promise.reject(error)
  }
)

export interface Server {
  id: number
  name: string
  host: string
  port: number
  username: string
  status: 'active' | 'inactive'
  is_local: boolean
  php_version?: string | null
  deploy_user?: string | null
  default_deploy_base?: string
  applications_count?: number
  created_at: string
  // Set only on trashed servers: when the server was deleted and when the
  // purge command will permanently remove it.
  deleted_at?: string | null
  purges_at?: string | null
}

export interface Application {
  id: number
  server_id: number
  git_provider_id: number | null
  name: string
  type: 'laravel' | 'nodejs' | 'static'
  node_version: string | null
  php_version?: string | null
  domain: string
  repository_url: string | null
  branch: string
  deploy_path: string
  deploy_script?: string
  deployment_strategy?: 'in_place' | 'atomic'
  build_command: string | null
  post_deploy_commands: string[] | null
  ssl_enabled: boolean
  status: 'active' | 'deploying' | 'failed'
  webhook_url?: string
  webhook_secret?: string
  deployments_count?: number
  server?: Server
  git_provider?: GitProvider
  domains?: Domain[]
  tags?: Tag[]
  created_at: string
}

export interface ScheduledTask {
  id: number
  server_id: number
  application_id: number | null
  command: string
  user: string
  frequency: 'minutely' | 'hourly' | 'nightly' | 'weekly' | 'monthly' | 'reboot' | 'custom'
  minute: string | null
  hour: string | null
  day: string | null
  month: string | null
  weekday: string | null
  cron_expression: string
  status: 'installing' | 'installed' | 'removing' | 'failed'
  log: string | null
  created_at: string
}

export type NotificationEvent = 'deployment_succeeded' | 'deployment_failed'

export interface NotificationChannel {
  id: number
  type: 'discord' | 'telegram' | 'email'
  name: string
  events: NotificationEvent[]
  is_enabled: boolean
  config_display: {
    has_webhook_url?: boolean
    has_bot_token?: boolean
    chat_id?: string | null
    has_api_key?: boolean
    from_email?: string | null
    to_email?: string | null
  }
  created_at: string
  updated_at: string
}

export interface Daemon {
  id: number
  server_id: number
  application_id: number | null
  command: string
  user: string
  directory: string
  processes: number
  status: 'installing' | 'installed' | 'removing' | 'failed'
  log: string | null
  created_at: string
}

export interface DaemonStatus {
  state: 'running' | 'degraded' | 'stopped'
  instances: Record<number, string>
}

export interface Deployment {
  id: number
  application_id: number
  commit_hash: string | null
  commit_message: string | null
  status: 'pending' | 'running' | 'success' | 'failed'
  log: string | null
  started_at: string | null
  finished_at: string | null
  created_at: string
  application?: Application
}

export interface EnvironmentVariable {
  id: number
  key: string
  created_at: string
  updated_at: string
}

export type OrganizationRole = 'owner' | 'admin' | 'member'

export interface Organization {
  id: number
  name: string
  role?: OrganizationRole
  created_at?: string
}

export interface OrganizationMember {
  id: number
  name: string
  email: string
  role: OrganizationRole
}

export interface OrganizationInvitation {
  id: number
  email: string
  role: OrganizationRole
  expires_at: string
  created_at: string
  // Only present on the create response
  token?: string
  accept_url?: string
}

export interface User {
  id: number
  name: string
  email: string
  // Optional: users cached in localStorage before organizations shipped
  // don't have these fields until the next /auth/user refresh.
  organizations?: Organization[]
  current_organization?: Organization | null
}

export interface GitProvider {
  id: number
  name: string
  type: 'gitlab' | 'github' | 'bitbucket'
  host: string | null
  username: string | null
  is_default: boolean
  has_private_key?: boolean
  has_access_token?: boolean
  applications_count?: number
  created_at: string
}

export interface Repository {
  id: number | string
  name: string
  full_name: string
  description: string
  url: string
  ssh_url: string
  https_url: string
  default_branch: string
  private: boolean
  updated_at: string | null
}

export interface Domain {
  id: number
  application_id: number
  domain: string
  is_primary: boolean
  ssl_enabled: boolean
  ssl_expires_at: string | null
  ssl_issuer: string | null
  created_at: string
  updated_at: string
}

export interface Tag {
  id: number
  server_id: number
  name: string
  color: string
  applications_count?: number
  created_at: string
  updated_at: string
}

export const TAG_COLORS = [
  'gray',
  'red',
  'orange',
  'amber',
  'yellow',
  'lime',
  'green',
  'emerald',
  'teal',
  'cyan',
  'blue',
  'indigo',
  'violet',
  'purple',
  'pink',
  'rose',
] as const

export type TagColor = typeof TAG_COLORS[number]

export interface SslStatus {
  exists: boolean
  valid: boolean
  expiry_date: string | null
  days_remaining: number | null
  issuer: string | null
}

export interface Database {
  id: number
  server_id: number
  name: string
  type: 'mysql' | 'postgresql'
  host: string
  port: number
  admin_user: string
  status: 'active' | 'inactive'
  charset: string | null
  collation: string | null
  created_at: string
  updated_at: string
}

export interface DatabaseInstallation {
  id: number
  server_id: number
  engine: 'mysql' | 'postgresql' | 'pm2' | 'php' | 'node' | 'nginx' | 'certbot'
  version_requested: string | null
  status: 'pending' | 'running' | 'success' | 'failed'
  log: string | null
  version_installed: string | null
  started_at: string | null
  finished_at: string | null
  created_at: string
  updated_at: string
}

export interface DatabaseUser {
  id: number
  database_id: number
  username: string
  host: string
  privileges: Record<string, string[]> | null
  status: 'active' | 'inactive'
  current_privileges?: { database: string; privileges: string[] }[]
  created_at: string
  updated_at: string
}

export interface DetectedDatabase {
  installed: boolean
  path: string
  version: string
  default_port: number
}

// Backup/restore run. Shared by the (not-yet-built) backups feature and the
// upload-restore feature here; only `kind: 'restore'` rows are relevant to
// databaseRestoresApi. upload_path is intentionally absent: BackupRun::$hidden
// hides it server-side because it's an internal storage path, not something
// the client ever needs.
export interface BackupRun {
  id: number
  backup_config_id: number | null
  database_id: number
  database_name: string
  kind: 'backup' | 'restore'
  trigger: 'cron' | 'manual'
  source: 's3' | 'upload' | null
  status: 'pending' | 'running' | 'success' | 'failed'
  failed_step: string | null
  s3_key: string | null
  original_filename: string | null
  format: 'sql' | 'sql_gz' | null
  safety_dump_path: string | null
  size_bytes: number | null
  duration_seconds: number | null
  log: string | null
  started_at: string | null
  finished_at: string | null
  created_at: string
  updated_at: string
  // Only loaded on the history list and single-run endpoints
  // (DatabaseRestoreController::index/show eager-load 'user:id,name').
  user?: { id: number; name: string } | null
}

export interface ServerMetrics {
  memory: {
    total: number
    used: number
    free: number
    available: number
    percentage: number
  }
  cpu: {
    usage: number
  }
  disk: {
    total: number
    used: number
    free: number
    percentage: number
  }
  uptime: {
    seconds: number
    formatted: string
  }
  load: {
    avg_1: number
    avg_5: number
    avg_15: number
  }
  collected_at: string
}

export interface SoftwareCheck {
  installed: boolean
  version: string | null
}

export type ServerSoftware = Record<string, SoftwareCheck>

export interface RepositoriesResponse {
  repositories: Repository[]
  page: number
  per_page: number
  has_more: boolean
}

// Auth
export const authApi = {
  login: async (email: string, password: string) => {
    await getCsrfCookie()
    return api.post<{ user: User; token: string }>('/auth/login', { email, password })
  },
  logout: () => api.post('/auth/logout'),
  getUser: () => api.get<User>('/auth/user'),
}

// Organizations
export const organizationsApi = {
  list: () => api.get<Organization[]>('/organizations'),
  create: (data: { name: string }) => api.post<Organization>('/organizations', data),
  update: (id: number, data: { name: string }) => api.put<Organization>(`/organizations/${id}`, data),
  delete: (id: number) => api.delete(`/organizations/${id}`),
  switch: (id: number) => api.post<Organization>(`/organizations/${id}/switch`),
  members: (id: number) => api.get<OrganizationMember[]>(`/organizations/${id}/members`),
  updateMemberRole: (id: number, userId: number, role: OrganizationRole) =>
    api.put<OrganizationMember>(`/organizations/${id}/members/${userId}`, { role }),
  removeMember: (id: number, userId: number) => api.delete(`/organizations/${id}/members/${userId}`),
  invitations: (id: number) => api.get<OrganizationInvitation[]>(`/organizations/${id}/invitations`),
  invite: (id: number, data: { email: string; role: OrganizationRole }) =>
    api.post<OrganizationInvitation>(`/organizations/${id}/invitations`, data),
  revokeInvitation: (id: number, invitationId: number) =>
    api.delete(`/organizations/${id}/invitations/${invitationId}`),
}

// Invitation accept flow (public)
export const invitationsApi = {
  show: (token: string) =>
    api.get<{ organization: string; email: string; role: OrganizationRole; expired: boolean; existing_user: boolean }>(
      `/invitations/${token}`
    ),
  accept: async (token: string, data?: { name: string; password: string; password_confirmation: string }) => {
    await getCsrfCookie()
    return api.post<{ user?: User; token?: string; organization: { id: number; name: string }; message?: string }>(
      `/invitations/${token}/accept`,
      data ?? {}
    )
  },
}

// SSH Keys
export const sshKeysApi = {
  generate: (comment?: string) =>
    api.post<{ private_key: string; public_key: string }>('/ssh-keys/generate', { comment }),
}

// Servers
export const serversApi = {
  list: () => api.get<Server[]>('/servers'),
  get: (id: number) => api.get<Server>('/servers/' + id),
  create: (data: Partial<Server> & { private_key: string }) =>
    api.post<Server>('/servers', data),
  update: (id: number, data: Partial<Server>) =>
    api.put<Server>('/servers/' + id, data),
  // Moves the server to the trash; recoverable via restore until the purge
  // command removes it.
  delete: (id: number) => api.delete('/servers/' + id),
  trashed: () => api.get<Server[]>('/servers/trashed'),
  restore: (id: number) => api.post<Server>('/servers/' + id + '/restore'),
  forceDelete: (id: number) => api.delete('/servers/' + id + '/force'),
  testConnection: (id: number) =>
    api.post<{ success: boolean; message: string; system_info?: string }>(
      '/servers/' + id + '/test-connection'
    ),
  testConnectionAdhoc: (data: { host: string; port?: number; username: string; private_key: string }) =>
    api.post<{ success: boolean; message: string; system_info?: string }>(
      '/servers/test-connection',
      data
    ),
  getNodeVersions: (id: number) =>
    api.get<{ versions: string[] }>('/servers/' + id + '/node-versions'),
  getRemoteNodeVersions: (id: number) =>
    api.get<{ versions: string[] }>('/servers/' + id + '/node-versions/remote'),
  setDefaultNodeVersion: (id: number, version: string) =>
    api.post<{ success: boolean; message: string }>('/servers/' + id + '/node-versions/default', { version }),
  getMetrics: (id: number) =>
    api.get<ServerMetrics>('/servers/' + id + '/metrics'),
  checkSoftware: (id: number) =>
    api.get<ServerSoftware>('/servers/' + id + '/software'),
}

// Web terminal (SSE for output, POST for input)
export const terminalApi = {
  open: (serverId: number, cols: number, rows: number) =>
    api.post<{ id: number }>('/servers/' + serverId + '/terminal-sessions', { cols, rows }),
  input: (sessionId: number, base64Data: string) =>
    api.post('/terminal-sessions/' + sessionId + '/input', { d: base64Data }),
  resize: (sessionId: number, cols: number, rows: number) =>
    api.post('/terminal-sessions/' + sessionId + '/resize', { cols, rows }),
  close: (sessionId: number) =>
    api.post('/terminal-sessions/' + sessionId + '/close'),
  // EventSource can't send headers, so the token rides in the query string
  // (same pattern as the deployment and installation log streams).
  streamUrl: (sessionId: number, token: string) =>
    '/api/terminal-sessions/' + sessionId + '/stream?token=' + encodeURIComponent(token),
}

// Tags (server-scoped)
export const tagsApi = {
  list: (serverId: number) =>
    api.get<Tag[]>('/servers/' + serverId + '/tags'),
  create: (serverId: number, data: { name: string; color?: string }) =>
    api.post<Tag>('/servers/' + serverId + '/tags', data),
  update: (serverId: number, tagId: number, data: { name?: string; color?: string }) =>
    api.put<Tag>('/servers/' + serverId + '/tags/' + tagId, data),
  delete: (serverId: number, tagId: number) =>
    api.delete('/servers/' + serverId + '/tags/' + tagId),
}

// Scheduled tasks (server-scoped)
export const scheduledTasksApi = {
  list: (serverId: number, applicationId?: number) =>
    api.get<ScheduledTask[]>('/servers/' + serverId + '/scheduled-tasks', {
      params: applicationId ? { application_id: applicationId } : undefined,
    }),
  get: (serverId: number, taskId: number) =>
    api.get<ScheduledTask>('/servers/' + serverId + '/scheduled-tasks/' + taskId),
  create: (serverId: number, data: {
    command: string
    user: string
    frequency: ScheduledTask['frequency']
    application_id?: number
    minute?: string
    hour?: string
    day?: string
    month?: string
    weekday?: string
  }) => api.post<ScheduledTask>('/servers/' + serverId + '/scheduled-tasks', data),
  delete: (serverId: number, taskId: number) =>
    api.delete('/servers/' + serverId + '/scheduled-tasks/' + taskId),
  output: (serverId: number, taskId: number, lines?: number) =>
    api.get<{ output: string; exists: boolean }>(
      '/servers/' + serverId + '/scheduled-tasks/' + taskId + '/output',
      { params: lines ? { lines } : undefined }
    ),
}

// Notification channels
export const notificationChannelsApi = {
  list: () => api.get<NotificationChannel[]>('/notification-channels'),
  create: (data: {
    name: string
    type: NotificationChannel['type']
    events: NotificationEvent[]
    is_enabled?: boolean
    config: Record<string, string>
  }) => api.post<NotificationChannel>('/notification-channels', data),
  update: (id: number, data: {
    name?: string
    events?: NotificationEvent[]
    is_enabled?: boolean
    config?: Record<string, string>
  }) => api.put<NotificationChannel>('/notification-channels/' + id, data),
  delete: (id: number) => api.delete('/notification-channels/' + id),
  test: (id: number) =>
    api.post<{ success: boolean; message: string }>('/notification-channels/' + id + '/test'),
}

// Daemons (server-scoped)
export const daemonsApi = {
  list: (serverId: number, applicationId?: number) =>
    api.get<Daemon[]>('/servers/' + serverId + '/daemons', {
      params: applicationId ? { application_id: applicationId } : undefined,
    }),
  get: (serverId: number, daemonId: number) =>
    api.get<Daemon>('/servers/' + serverId + '/daemons/' + daemonId),
  create: (serverId: number, data: {
    command: string
    user: string
    directory?: string
    processes?: number
    application_id?: number
  }) => api.post<Daemon>('/servers/' + serverId + '/daemons', data),
  delete: (serverId: number, daemonId: number) =>
    api.delete('/servers/' + serverId + '/daemons/' + daemonId),
  restart: (serverId: number, daemonId: number) =>
    api.post<{ message: string }>('/servers/' + serverId + '/daemons/' + daemonId + '/restart'),
  status: (serverId: number, daemonId: number) =>
    api.get<DaemonStatus>('/servers/' + serverId + '/daemons/' + daemonId + '/status'),
  output: (serverId: number, daemonId: number, lines?: number) =>
    api.get<{ output: string; exists: boolean }>(
      '/servers/' + serverId + '/daemons/' + daemonId + '/output',
      { params: lines ? { lines } : undefined }
    ),
}

// Databases (server-scoped)
export const databasesApi = {
  detect: (serverId: number) =>
    api.get<Record<string, DetectedDatabase>>('/servers/' + serverId + '/databases/detect'),
  list: (serverId: number) =>
    api.get<Database[]>('/servers/' + serverId + '/databases'),
  get: (serverId: number, databaseId: number) =>
    api.get<Database>('/servers/' + serverId + '/databases/' + databaseId),
  create: (serverId: number, data: {
    name: string
    type: 'mysql' | 'postgresql'
    host?: string
    port?: number
    admin_user: string
    admin_password: string
    charset?: string
    collation?: string
  }) => api.post<Database>('/servers/' + serverId + '/databases', data),
  update: (serverId: number, databaseId: number, data: {
    name?: string
    host?: string
    port?: number
    admin_user?: string
    admin_password?: string
    status?: 'active' | 'inactive'
    charset?: string
    collation?: string
  }) => api.put<Database>('/servers/' + serverId + '/databases/' + databaseId, data),
  delete: (serverId: number, databaseId: number) =>
    api.delete('/servers/' + serverId + '/databases/' + databaseId),
  testConnection: (serverId: number, databaseId: number) =>
    api.post<{ success: boolean; message: string; version?: string }>(
      '/servers/' + serverId + '/databases/' + databaseId + '/test'
    ),
  // Remote database operations
  listRemoteDatabases: (serverId: number, databaseId: number) =>
    api.get<{ databases: string[] }>(
      '/servers/' + serverId + '/databases/' + databaseId + '/remote-databases'
    ),
  createRemoteDatabase: (serverId: number, databaseId: number, data: {
    name: string
    charset?: string
    collation?: string
  }) => api.post<{ message: string }>(
    '/servers/' + serverId + '/databases/' + databaseId + '/remote-databases', data
  ),
  dropRemoteDatabase: (serverId: number, databaseId: number, name: string) =>
    api.delete<{ message: string }>(
      '/servers/' + serverId + '/databases/' + databaseId + '/remote-databases',
      { data: { name } }
    ),
  // Database installation
  install: (serverId: number, engine: 'mysql' | 'postgresql' | 'pm2' | 'php' | 'node' | 'nginx' | 'certbot', version?: string) =>
    api.post<DatabaseInstallation>('/servers/' + serverId + '/databases/install', { engine, version }),
  installations: (serverId: number) =>
    api.get<DatabaseInstallation[]>('/servers/' + serverId + '/database-installations'),
  installationStatus: (installationId: number) =>
    api.get<DatabaseInstallation>('/database-installations/' + installationId),
}

// Database Users
export const databaseUsersApi = {
  list: (serverId: number, databaseId: number) =>
    api.get<DatabaseUser[]>(
      '/servers/' + serverId + '/databases/' + databaseId + '/users'
    ),
  listRemote: (serverId: number, databaseId: number) =>
    api.get<{ users: { username: string; host: string }[] }>(
      '/servers/' + serverId + '/databases/' + databaseId + '/users/remote'
    ),
  get: (serverId: number, databaseId: number, userId: number) =>
    api.get<DatabaseUser>(
      '/servers/' + serverId + '/databases/' + databaseId + '/users/' + userId
    ),
  create: (serverId: number, databaseId: number, data: {
    username: string
    password: string
    host?: string
  }) => api.post<DatabaseUser>(
    '/servers/' + serverId + '/databases/' + databaseId + '/users', data
  ),
  delete: (serverId: number, databaseId: number, userId: number) =>
    api.delete('/servers/' + serverId + '/databases/' + databaseId + '/users/' + userId),
  grantPrivileges: (serverId: number, databaseId: number, userId: number, data: {
    database: string
    privileges: string[]
  }) => api.post<{ message: string; user: DatabaseUser }>(
    '/servers/' + serverId + '/databases/' + databaseId + '/users/' + userId + '/grant', data
  ),
  revokePrivileges: (serverId: number, databaseId: number, userId: number, data: {
    database: string
    privileges: string[]
  }) => api.post<{ message: string; user: DatabaseUser }>(
    '/servers/' + serverId + '/databases/' + databaseId + '/users/' + userId + '/revoke', data
  ),
}

// Database restores (upload a .sql/.sql.gz dump, restore it on the server).
// The upload endpoint returns 202 with the created BackupRun on success; the
// caller is expected to follow it via RestoreLogPanel's SSE stream
// (GET /backup-runs/{id}/stream), not by polling this module.
export const databaseRestoresApi = {
  // Newest first, capped at 20 server-side (DatabaseRestoreController::index).
  list: (serverId: number, databaseId: number) =>
    api.get<{ data: BackupRun[] }>(
      '/servers/' + serverId + '/databases/' + databaseId + '/restores'
    ),

  // Single-run fetch, used both as the SSE fallback poll and to pull fields
  // (like safety_dump_path) that the stream's "connected"/"complete" events
  // don't carry.
  get: (runId: number) => api.get<{ data: BackupRun }>('/backup-runs/' + runId),

  // Multipart upload with real progress. The instance-level default
  // 'Content-Type: application/json' (set above) must not survive into this
  // request: axios's default transformRequest checks headers.getContentType()
  // for 'application/json' BEFORE it ever looks at the adapter, and if that
  // check matches, it runs the FormData through JSON.stringify(formDataToJSON(data))
  // instead of sending it as-is, silently discarding the file. Setting
  // 'Content-Type': undefined here clears the header before that check runs
  // (verified against the installed axios 1.13.3: this is the same mechanism
  // recommended by axios's own resolveConfig(), which does an equivalent
  // headers.setContentType(undefined) for FormData bodies in a standard
  // browser environment). With the header cleared, transformRequest leaves the
  // FormData untouched, and the browser fills in the multipart boundary itself.
  upload: (
    serverId: number,
    databaseId: number,
    payload: {
      dump: File
      targetDatabase: string
      overwrite: boolean
      confirmName?: string
    },
    onProgress?: (percent: number) => void
  ) => {
    const form = new FormData()
    form.append('dump', payload.dump)
    form.append('target_database', payload.targetDatabase)
    form.append('overwrite', payload.overwrite ? '1' : '0')
    if (payload.confirmName) {
      form.append('confirm_name', payload.confirmName)
    }

    return api.post<{ data: BackupRun }>(
      '/servers/' + serverId + '/databases/' + databaseId + '/restores',
      form,
      {
        headers: { 'Content-Type': undefined },
        onUploadProgress: (event) => {
          if (onProgress && event.total) {
            onProgress(Math.round((event.loaded * 100) / event.total))
          }
        },
      }
    )
  },
}

// Git Providers
export const gitProvidersApi = {
  list: () => api.get<GitProvider[]>('/git-providers'),
  get: (id: number) => api.get<GitProvider & { applications: Application[] }>('/git-providers/' + id),
  create: (data: {
    name: string
    type: 'gitlab' | 'github' | 'bitbucket'
    host?: string
    access_token?: string
    private_key?: string
    username?: string
    is_default?: boolean
  }) => api.post<GitProvider>('/git-providers', data),
  update: (id: number, data: {
    name?: string
    type?: 'gitlab' | 'github' | 'bitbucket'
    host?: string | null
    access_token?: string
    private_key?: string
    username?: string | null
    is_default?: boolean
  }) => api.put<GitProvider>('/git-providers/' + id, data),
  delete: (id: number) => api.delete('/git-providers/' + id),
  testConnection: (id: number) =>
    api.post<{ success: boolean; username: string; name: string; email?: string; message?: string }>(
      '/git-providers/' + id + '/test'
    ),
  listRepositories: (id: number, search?: string, page?: number, perPage?: number) =>
    api.get<RepositoriesResponse>('/git-providers/' + id + '/repositories', {
      params: { search, page, per_page: perPage }
    }),
  listBranches: (id: number, repository: string) =>
    api.get<{ branches: { name: string; default: boolean }[] }>('/git-providers/' + id + '/branches', {
      params: { repository }
    }),
}

// Applications
export const applicationsApi = {
  list: () => api.get<Application[]>('/applications'),
  importFromServer: (serverId: number) =>
    api.post<{
      imported: Application[]
      skipped: { path: string; reason: string }[]
      warnings: { path: string; warning: string }[]
    }>('/servers/' + serverId + '/applications/import'),
  get: (id: number) => api.get<Application>('/applications/' + id),
  create: (data: Partial<Application>) =>
    api.post<{ application: Application; webhook_url: string; webhook_secret: string }>(
      '/applications',
      data
    ),
  update: (id: number, data: Partial<Application>) =>
    api.put<Application>('/applications/' + id, data),
  delete: (id: number, options?: { deleteFiles?: boolean }) =>
    api.delete('/applications/' + id, { params: { delete_files: options?.deleteFiles ? 1 : 0 } }),
  deploy: (id: number, commitHash?: string) =>
    api.post<{ message: string; deployment_id: number }>('/applications/' + id + '/deploy', { commit_hash: commitHash }),
  setupSsl: (id: number, email: string) =>
    api.post('/applications/' + id + '/setup-ssl', { email }),
  getDeployments: (id: number) =>
    api.get<{ data: Deployment[] }>('/applications/' + id + '/deployments'),
  getDeployScript: (id: number) =>
    api.get<{ deploy_script: string }>('/applications/' + id + '/deploy-script'),
  updateDeployScript: (id: number, deployScript: string) =>
    api.put<{ message: string; deploy_script: string }>('/applications/' + id + '/deploy-script', {
      deploy_script: deployScript,
    }),
  getDefaultScript: (type: 'laravel' | 'nodejs' | 'static') =>
    api.post<{ deploy_script: string }>('/applications/default-script', { type }),
  generateDeployPath: (name: string, serverId?: number) =>
    api.post<{ deploy_path: string }>('/applications/generate-path', { name, server_id: serverId }),
  syncTags: (id: number, tagIds: number[]) =>
    api.put<Tag[]>('/applications/' + id + '/tags', { tag_ids: tagIds }),
  getReleases: (id: number) =>
    api.get<{ releases: Release[]; current_deployment_id: number | null }>(
      '/applications/' + id + '/releases'
    ),
  rollback: (id: number, deploymentId: number) =>
    api.post<{ message: string; deployment_id: number; target_release_id: string }>(
      '/applications/' + id + '/rollback',
      { deployment_id: deploymentId }
    ),
  rollbackToPrevious: (id: number) =>
    api.post<{ message: string; deployment_id: number; target_release_id: string }>(
      '/applications/' + id + '/rollback/previous'
    ),
}

export interface Release {
  release_id: string
  deployment_id: number | null
  is_active: boolean
  commit_hash: string | null
  commit_message: string | null
  created_at: string | null
}

// Environment Variables
export const envApi = {
  list: (appId: number) =>
    api.get<EnvironmentVariable[]>('/applications/' + appId + '/env'),
  create: (appId: number, key: string, value: string) =>
    api.post<EnvironmentVariable>('/applications/' + appId + '/env', { key, value }),
  update: (appId: number, id: number, key: string, value: string) =>
    api.put<EnvironmentVariable>('/applications/' + appId + '/env/' + id, { key, value }),
  delete: (appId: number, id: number) =>
    api.delete('/applications/' + appId + '/env/' + id),
  // Full .env file content
  getContent: (appId: number) =>
    api.get<{ content: string }>('/applications/' + appId + '/env-file'),
  updateContent: (appId: number, content: string) =>
    api.put<{ message: string; sync_result: { synced: boolean; message: string; error?: string } }>('/applications/' + appId + '/env-file', { content }),
}

// Deployments
export const deploymentsApi = {
  get: (id: number) => api.get<Deployment>('/deployments/' + id),
}

// Domains
export const domainsApi = {
  list: (appId: number) =>
    api.get<Domain[]>('/applications/' + appId + '/domains'),
  create: (appId: number, domain: string) =>
    api.post<Domain>('/applications/' + appId + '/domains', { domain }),
  delete: (appId: number, domainId: number) =>
    api.delete('/applications/' + appId + '/domains/' + domainId),
  setPrimary: (appId: number, domainId: number) =>
    api.put<Domain>('/applications/' + appId + '/domains/' + domainId + '/primary'),
  requestSsl: (appId: number, domainId: number, email: string) =>
    api.post<{ message: string; domain: Domain }>('/applications/' + appId + '/domains/' + domainId + '/ssl', { email }),
  getSslStatus: (appId: number, domainId: number) =>
    api.get<SslStatus>('/applications/' + appId + '/domains/' + domainId + '/ssl'),
}

// Nginx Configuration
export const nginxApi = {
  getConfig: (appId: number) =>
    api.get<{ content: string }>('/applications/' + appId + '/nginx'),
  updateConfig: (appId: number, content: string) =>
    api.put<{ message: string }>('/applications/' + appId + '/nginx', { content }),
}

// Log types
export interface LogFile {
  name: string
  path: string
  size: number
  last_modified: string
}

export interface LogContent {
  content: string
  filename: string
  total_lines: number
  returned_lines: number
  file_size: number
}

// Logs
export const logsApi = {
  listFiles: (appId: number) =>
    api.get<{ files: LogFile[] }>('/applications/' + appId + '/logs'),
  getContent: (appId: number, filename: string, lines?: number, search?: string) =>
    api.get<LogContent>('/applications/' + appId + '/logs/' + encodeURIComponent(filename), {
      params: { lines, search: search || undefined },
    }),
}

// Server SSH keys (server-scoped)
export interface ServerSshKey {
  id: number
  server_id: number
  name: string
  public_key: string
  fingerprint: string
  username: string
  status: 'installing' | 'installed' | 'removing' | 'failed'
  error: string | null
  created_at: string
  updated_at: string
}

export interface AuthorizedKeyEntry {
  type: string
  comment: string
  fingerprint: string | null
  tracked: boolean
}

export const serverSshKeysApi = {
  list: (serverId: number) =>
    api.get<ServerSshKey[]>('/servers/' + serverId + '/ssh-keys'),
  create: (serverId: number, data: { name: string; username: string; public_key: string }) =>
    api.post<ServerSshKey>('/servers/' + serverId + '/ssh-keys', data),
  delete: (serverId: number, sshKeyId: number) =>
    api.delete<{ message: string }>('/servers/' + serverId + '/ssh-keys/' + sshKeyId),
  authorized: (serverId: number, username?: string) =>
    api.get<{ username: string; keys: AuthorizedKeyEntry[] }>(
      '/servers/' + serverId + '/ssh-keys/authorized',
      { params: username ? { username } : undefined }
    ),
}

// sshd hardening settings (server-scoped)
export interface SshdSettings {
  password_authentication: 'yes' | 'no'
  permit_root_login: 'yes' | 'no' | 'prohibit-password'
  supports_include: boolean
}

export const sshdApi = {
  get: (serverId: number) =>
    api.get<SshdSettings>('/servers/' + serverId + '/sshd-settings'),
  update: (serverId: number, data: {
    password_authentication: 'yes' | 'no'
    permit_root_login: 'yes' | 'no' | 'prohibit-password'
  }) => api.put<SshdSettings>('/servers/' + serverId + '/sshd-settings', data),
}

// Firewall (UFW, server-scoped)
export interface FirewallRule {
  number: number
  to: string
  action: string
  from: string
  v6: boolean
}

export interface FirewallStatus {
  installed: boolean
  active: boolean
  rules: FirewallRule[]
}

export interface FirewallRuleSpec {
  port: string
  protocol: 'tcp' | 'udp' | 'both'
  source?: string | null
}

export const firewallApi = {
  status: (serverId: number) =>
    api.get<FirewallStatus>('/servers/' + serverId + '/firewall'),
  addRule: (serverId: number, data: FirewallRuleSpec) =>
    api.post<FirewallStatus>('/servers/' + serverId + '/firewall/rules', data),
  deleteRule: (serverId: number, data: FirewallRuleSpec) =>
    api.delete<FirewallStatus>('/servers/' + serverId + '/firewall/rules', { data }),
  enable: (serverId: number) =>
    api.post<FirewallStatus>('/servers/' + serverId + '/firewall/enable'),
  disable: (serverId: number) =>
    api.post<FirewallStatus>('/servers/' + serverId + '/firewall/disable'),
  install: (serverId: number) =>
    api.post<FirewallStatus>('/servers/' + serverId + '/firewall/install'),
}

// Server users (server-scoped)
export interface ServerUser {
  name: string
  uid: number
  home: string
  shell: string
  has_sudo: boolean
  is_connection_user: boolean
}

export const serverUsersApi = {
  list: (serverId: number) =>
    api.get<{ users: ServerUser[] }>('/servers/' + serverId + '/users'),
  create: (serverId: number, data: { username: string; sudo: boolean; use_as_deploy_user?: boolean }) =>
    api.post<{ message: string; username: string; sudo: boolean; server: Server }>(
      '/servers/' + serverId + '/users', data
    ),
  switchUser: (serverId: number, data: { username: string; fix_ownership: boolean }) =>
    api.post<Server>('/servers/' + serverId + '/switch-user', data),
  setDeployUser: (serverId: number, data: { username: string }) =>
    api.post<Server>('/servers/' + serverId + '/deploy-user', data),
}

// System
export interface SystemVersion {
  current_version: string
  latest_version: string
  update_available: boolean
}

export interface UpdateStatus {
  running: boolean
  status: 'idle' | 'running' | 'completed' | 'failed'
  log: string
}

export interface SystemEnvironment {
  is_docker: boolean
  docker_host_ip: string | null
}

export const systemApi = {
  getVersion: () => api.get<SystemVersion>('/system/version'),
  getEnvironment: () => api.get<SystemEnvironment>('/system/environment'),
  startUpdate: () => api.post<{ success: boolean; message: string }>('/system/update'),
  getUpdateStatus: () => api.get<UpdateStatus>('/system/update-status'),
}

export default api
