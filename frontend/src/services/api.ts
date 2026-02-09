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

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('token')
      localStorage.removeItem('user')
      window.location.href = '/app/login'
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
  applications_count?: number
  created_at: string
}

export interface Application {
  id: number
  server_id: number
  git_provider_id: number | null
  name: string
  type: 'laravel' | 'nodejs' | 'static'
  node_version: string | null
  domain: string
  repository_url: string
  branch: string
  deploy_path: string
  deploy_script?: string
  build_command: string | null
  post_deploy_commands: string[] | null
  ssl_enabled: boolean
  status: 'active' | 'deploying' | 'failed'
  webhook_secret?: string
  deployments_count?: number
  server?: Server
  git_provider?: GitProvider
  domains?: Domain[]
  tags?: Tag[]
  created_at: string
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

export interface User {
  id: number
  name: string
  email: string
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
  engine: 'mysql' | 'postgresql' | 'pm2'
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
  delete: (id: number) => api.delete('/servers/' + id),
  testConnection: (id: number) =>
    api.post<{ success: boolean; message: string; system_info?: string }>(
      '/servers/' + id + '/test-connection'
    ),
  getNodeVersions: (id: number) =>
    api.get<{ versions: string[] }>('/servers/' + id + '/node-versions'),
  getMetrics: (id: number) =>
    api.get<ServerMetrics>('/servers/' + id + '/metrics'),
  checkSoftware: (id: number) =>
    api.get<ServerSoftware>('/servers/' + id + '/software'),
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
  install: (serverId: number, engine: 'mysql' | 'postgresql' | 'pm2') =>
    api.post<DatabaseInstallation>('/servers/' + serverId + '/databases/install', { engine }),
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
  get: (id: number) => api.get<Application>('/applications/' + id),
  create: (data: Partial<Application>) =>
    api.post<{ application: Application; webhook_url: string; webhook_secret: string }>(
      '/applications',
      data
    ),
  update: (id: number, data: Partial<Application>) =>
    api.put<Application>('/applications/' + id, data),
  delete: (id: number) => api.delete('/applications/' + id),
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
  generateDeployPath: (name: string) =>
    api.post<{ deploy_path: string }>('/applications/generate-path', { name }),
  syncTags: (id: number, tagIds: number[]) =>
    api.put<Tag[]>('/applications/' + id + '/tags', { tag_ids: tagIds }),
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
    api.put<{ message: string }>('/applications/' + appId + '/env-file', { content }),
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

export const systemApi = {
  getVersion: () => api.get<SystemVersion>('/system/version'),
  startUpdate: () => api.post<{ success: boolean; message: string }>('/system/update'),
  getUpdateStatus: () => api.get<UpdateStatus>('/system/update-status'),
}

export default api
