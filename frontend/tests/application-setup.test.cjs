/* eslint-env node */
/* eslint-disable @typescript-eslint/no-var-requires */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
const ts = require('typescript')

// Exercise the actual setup coordinator with isolated API responses, without a server.
function fixture({ failEnvironment = false, collision = false } = {}) {
  const calls = []
  const mock = (name, value) => async (...args) => {
    calls.push({ name, args })
    if (name === 'environment' && failEnvironment) {
      failEnvironment = false
      throw new Error('Environment unavailable')
    }
    return { data: value }
  }
  const api = {
    applicationsApi: { create: mock('application', { application: { id: 10 } }) },
    databasesApi: {
      listRemoteDatabases: mock('checkDatabase', { databases: collision ? ['sample'] : [] }),
      createRemoteDatabase: mock('database', {}),
    },
    databaseUsersApi: {
      listRemote: mock('checkUser', { users: [] }),
      create: mock('user', { id: 20 }),
      grantPrivileges: mock('grant', {}),
    },
    envApi: {
      getTemplate: mock('template', { content: 'APP_KEY=generated-once\nAPP_DEBUG=false\n' }),
      updateContent: mock('environment', {}),
    },
  }
  const source = fs.readFileSync(path.join(__dirname, '../src/pages/apps/new/setup.ts'), 'utf8')
  const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText
  const exports = {}
  vm.runInNewContext(output, { exports, require: () => api })
  const configuration = {
    domain: '', environment: 'APP_NAME=Sample', productionDefaults: true,
    databaseId: '2', databaseName: 'sample', databaseUsername: 'sample_user', databasePassword: 'strong$"\\password',
  }
  const database = { id: 2, type: 'mysql', host: 'localhost', port: 3306 }
  return { ...exports, calls, configuration, database }
}

test('configuration completes in dependency order with database-scoped privileges and escaped secrets', async () => {
  const f = fixture()
  await f.setupApplication({ server_id: 1 }, f.configuration, f.database, {}, () => {})
  assert.deepEqual(f.calls.map(c => c.name), ['checkDatabase', 'checkUser', 'application', 'database', 'user', 'grant', 'template', 'environment'])
  const grant = f.calls.find(c => c.name === 'grant').args[3]
  assert.equal(grant.database, 'sample')
  assert.equal(grant.privileges.join(','), 'ALL')
  const env = f.calls.find(c => c.name === 'environment').args[1]
  assert.ok(env.includes('DB_CONNECTION="mysql"'))
  assert.ok(env.includes('DB_HOST="127.0.0.1"'))
  assert.ok(env.includes('DB_PASSWORD="strong\\$\\"\\\\password"'))
  assert.ok(env.includes('DB_URL=""'))
})

test('retry after environment failure retains application, database, user and generated key', async () => {
  const f = fixture({ failEnvironment: true })
  const progress = {}
  await assert.rejects(f.setupApplication({ server_id: 1 }, f.configuration, f.database, progress, () => {}), /Environment unavailable/)
  assert.equal(progress.environmentSaved, undefined)
  await f.setupApplication({ server_id: 1 }, f.configuration, f.database, progress, () => {})
  for (const name of ['application', 'database', 'user', 'grant', 'template']) {
    assert.equal(f.calls.filter(c => c.name === name).length, 1, name)
  }
  assert.equal(f.calls.filter(c => c.name === 'environment').length, 2)
  assert.equal(progress.environmentSaved, true)
})

test('existing database name is rejected before creating resources', async () => {
  const f = fixture({ collision: true })
  await assert.rejects(f.setupApplication({ server_id: 1 }, f.configuration, f.database, {}, () => {}), /already exists/)
  assert.deepEqual(f.calls.map(c => c.name), ['checkDatabase', 'checkUser'])
})

test('empty environment preserves server-managed configuration', async () => {
  const f = fixture()
  await f.setupApplication({ server_id: 1 }, { ...f.configuration, environment: '', productionDefaults: false }, undefined, {}, () => {})
  assert.deepEqual(f.calls.map(c => c.name), ['application'])
})

test('PostgreSQL credentials use pgsql and the configured port', async () => {
  const f = fixture()
  await f.setupApplication({ server_id: 1 }, f.configuration, { ...f.database, type: 'postgresql', port: 5432 }, {}, () => {})
  const env = f.calls.find(c => c.name === 'environment').args[1]
  assert.ok(env.includes('DB_CONNECTION="pgsql"'))
  assert.ok(env.includes('DB_PORT="5432"'))
})
