import { applicationsApi, databasesApi, databaseUsersApi, envApi, Application, Database } from '@/services/api'

export interface SetupProgress {
  application?: Application
  databaseCreated?: boolean
  userId?: number
  privilegesGranted?: boolean
  environmentSaved?: boolean
  template?: string
  deploymentRequested?: boolean
  deploymentId?: number
}

export interface Configuration {
  domain: string
  environment: string
  productionDefaults: boolean
  databaseId: string
  databaseName: string
  databaseUsername: string
  databasePassword: string
}

// Match EnvFile's double-quoted representation, including literal dollar signs.
export function envLine(key: string, value: string) {
  const escaped = value.replace(/[\\"$\n\r\t]/g, char => ({ '\\': '\\\\', '"': '\\"', '$': '\\$', '\n': '\\n', '\r': '\\r', '\t': '\\t' })[char]!)
  return `${key}="${escaped}"`
}

export async function setupApplication(
  payload: Partial<Application>, configuration: Configuration, database: Database | undefined,
  progress: SetupProgress, report: (message: string) => void,
) {
  const serverId = payload.server_id!
  if (!progress.application) {
    if (database) {
      report('Checking database and user names…')
      const [databases, users] = await Promise.all([
        databasesApi.listRemoteDatabases(serverId, database.id),
        databaseUsersApi.listRemote(serverId, database.id),
      ])
      if (databases.data.databases.includes(configuration.databaseName) || users.data.users.some(user => user.username === configuration.databaseUsername)) {
        throw new Error('That database or username already exists. Choose unused names, or configure existing credentials in the environment instead.')
      }
    }
    report('Creating application…')
    progress.application = (await applicationsApi.create(payload)).data.application
  }
  const application = progress.application
  if (database) {
    if (!progress.databaseCreated) {
      report('Creating database…')
      await databasesApi.createRemoteDatabase(serverId, database.id, { name: configuration.databaseName })
      progress.databaseCreated = true
    }
    if (!progress.userId) {
      report('Creating database user…')
      progress.userId = (await databaseUsersApi.create(serverId, database.id, {
        username: configuration.databaseUsername, password: configuration.databasePassword,
        host: database.type === 'mysql' ? 'localhost' : '*',
      })).data.id
    }
    if (!progress.privilegesGranted) {
      report('Granting access to the application database…')
      await databaseUsersApi.grantPrivileges(serverId, database.id, progress.userId, {
        database: configuration.databaseName, privileges: ['ALL'],
      })
      progress.privilegesGranted = true
    }
  }
  if (!progress.environmentSaved) {
    report('Saving environment…')
    let content = configuration.environment
    if (configuration.productionDefaults) {
      if (progress.template === undefined) progress.template = (await envApi.getTemplate(application.id)).data.content
      content = progress.template + '\n' + content
    }
    if (database) {
      const values = {
        DB_URL: '', DATABASE_URL: '',
        DB_CONNECTION: database.type === 'mysql' ? 'mysql' : 'pgsql',
        DB_HOST: database.host === 'localhost' ? '127.0.0.1' : database.host,
        DB_PORT: String(database.port), DB_DATABASE: configuration.databaseName,
        DB_USERNAME: configuration.databaseUsername, DB_PASSWORD: configuration.databasePassword,
      }
      content += '\n' + Object.entries(values).map(([key, value]) => envLine(key, value)).join('\n')
    }
    if (content.trim()) await envApi.updateContent(application.id, content)
    progress.environmentSaved = true
  }
  return application
}
