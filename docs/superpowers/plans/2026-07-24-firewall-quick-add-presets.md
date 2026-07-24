# Firewall Quick Add Presets Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Common services" help section with quick add preset buttons (HTTP, HTTPS, MySQL, PostgreSQL, Redis) to the server Network settings firewall panel.

**Architecture:** Frontend only. All changes live in `frontend/src/pages/servers/settings/NetworkSettingsSection.tsx`. Web presets call the existing `firewallApi.addRule` directly with one click. Database presets open the existing Add rule dialog in a new "preset mode" that locks port and protocol and requires a source IP. No backend changes.

**Tech Stack:** React 18, TypeScript, Tailwind CSS, shadcn/Radix UI components, lucide-react icons.

**Spec:** `docs/superpowers/specs/2026-07-24-firewall-quick-add-presets-design.md`

**Verification note:** The frontend has no unit test framework (no test script in `frontend/package.json`), so per the spec, verification is `npm run lint`, `npm run build`, and manual UI verification. The project `verify` skill describes how to drive the UI end to end.

---

### Task 1: Branch, preset data, and helper

**Files:**
- Modify: `frontend/src/pages/servers/settings/NetworkSettingsSection.tsx`

- [ ] **Step 1: Create the feature branch**

```bash
git checkout -b feature/firewall-quick-add-presets
```

- [ ] **Step 2: Add the Check icon import**

In `NetworkSettingsSection.tsx`, change the lucide-react import (currently line 58):

```tsx
import { Check, Plus, Trash2 } from 'lucide-react'
```

- [ ] **Step 3: Add preset definitions and the allowed-port helper**

Insert directly below the `parseRuleSpec` function (after its closing brace, currently line 85), above the component:

```tsx
interface ServicePreset {
  name: string
  port: string
  requiresSource: boolean
}

const SERVICE_PRESETS: ServicePreset[] = [
  { name: 'HTTP', port: '80', requiresSource: false },
  { name: 'HTTPS', port: '443', requiresSource: false },
  { name: 'MySQL', port: '3306', requiresSource: true },
  { name: 'PostgreSQL', port: '5432', requiresSource: true },
  { name: 'Redis', port: '6379', requiresSource: true },
]

function isPortAllowed(rules: FirewallRule[], port: string): boolean {
  return rules.some(
    (rule) => !rule.v6 && rule.to === `${port}/tcp` && rule.action === 'ALLOW IN'
  )
}
```

- [ ] **Step 4: Verify it compiles**

```bash
cd frontend && npm run build
```

Expected: build succeeds. TypeScript may warn about unused values (`Check`, `SERVICE_PRESETS`, `isPortAllowed`); if the build fails only because of unused declarations, that is expected at this stage and Task 2 resolves it. Do not commit yet in that case, just proceed.

- [ ] **Step 5: Commit (only if the build passes)**

```bash
git add frontend/src/pages/servers/settings/NetworkSettingsSection.tsx
git commit -m "Add firewall service preset definitions"
```

---

### Task 2: Preset state and click handler

**Files:**
- Modify: `frontend/src/pages/servers/settings/NetworkSettingsSection.tsx`

- [ ] **Step 1: Add preset state**

Below the existing `ruleToDelete` state declarations (currently lines 102 and 103), add:

```tsx
const [addingPresetPort, setAddingPresetPort] = useState<string | null>(null)
const [activePreset, setActivePreset] = useState<ServicePreset | null>(null)
```

- [ ] **Step 2: Clear the preset when opening the generic dialog**

Replace the existing `openAddRuleDialog` function:

```tsx
const openAddRuleDialog = () => {
  setActivePreset(null)
  setRuleForm({ port: '', protocol: 'tcp', source: '' })
  setShowAddRuleDialog(true)
}
```

- [ ] **Step 3: Add the preset click handler**

Insert directly below `openAddRuleDialog`:

```tsx
const handlePresetClick = async (preset: ServicePreset) => {
  if (preset.requiresSource) {
    setActivePreset(preset)
    setRuleForm({ port: preset.port, protocol: 'tcp', source: '' })
    setShowAddRuleDialog(true)
    return
  }
  setAddingPresetPort(preset.port)
  try {
    const response = await firewallApi.addRule(server.id, {
      port: preset.port,
      protocol: 'tcp',
      source: null,
    })
    setStatus(response.data)
    toast.success(`${preset.name} (${preset.port}/tcp) allowed`)
  } catch (error: unknown) {
    toast.error(getErrorMessage(error, 'Failed to add firewall rule'))
  } finally {
    setAddingPresetPort(null)
  }
}
```

- [ ] **Step 4: Require a source in preset mode when submitting**

In `handleAddRule`, directly after the existing empty-port guard (`if (!ruleForm.port.trim()) { ... }`), add:

```tsx
if (activePreset?.requiresSource && !ruleForm.source.trim()) {
  toast.error('Enter a source IP to restrict database access')
  return
}
```

- [ ] **Step 5: Verify it compiles**

```bash
cd frontend && npm run build
```

Expected: build succeeds (unused-value warnings for `handlePresetClick`, `addingPresetPort`, and `isPortAllowed` may still fail the build; as in Task 1, that is acceptable, proceed to Task 3 and commit there).

- [ ] **Step 6: Commit (only if the build passes)**

```bash
git add frontend/src/pages/servers/settings/NetworkSettingsSection.tsx
git commit -m "Add firewall preset state and handlers"
```

---

### Task 3: Common services UI block

**Files:**
- Modify: `frontend/src/pages/servers/settings/NetworkSettingsSection.tsx`

- [ ] **Step 1: Render the block**

In the JSX, between the firewall status toggle `div` (the one ending with `<Switch ... />` and its closing `</div>`, currently line 259) and the `Rules` header `div` (currently starting line 261), insert:

```tsx
<div className="space-y-3 pb-4 border-b">
  <div>
    <p className="font-medium">Common services</p>
    <p className="text-sm text-muted-foreground">
      Websites served from this server need HTTP (80) and HTTPS (443) open to
      anywhere. Port 80 is also required for Let's Encrypt certificate
      challenges. Database ports should only be opened to specific trusted IPs,
      since applications running on this server connect over localhost.
    </p>
  </div>
  <div className="flex flex-wrap gap-2">
    {SERVICE_PRESETS.map((preset) => {
      const allowed = isPortAllowed(status.rules, preset.port)
      return (
        <Button
          key={preset.port}
          variant="outline"
          size="sm"
          disabled={allowed || addingPresetPort === preset.port}
          onClick={() => handlePresetClick(preset)}
        >
          {addingPresetPort === preset.port ? (
            <LoadingSpinner size="sm" className="mr-2" />
          ) : allowed ? (
            <Check className="h-4 w-4 mr-2" />
          ) : (
            <Plus className="h-4 w-4 mr-2" />
          )}
          {preset.name} {preset.port}
          {allowed && <span className="ml-1 text-muted-foreground">allowed</span>}
        </Button>
      )
    })}
  </div>
</div>
```

Note: in JSX, `Let's` inside a text node is fine as plain text since the block uses double-quoted attributes only. If the linter complains about the unescaped apostrophe (react/no-unescaped-entities), write it as `Let&apos;s`.

- [ ] **Step 2: Build and lint**

```bash
cd frontend && npm run build && npm run lint
```

Expected: both pass with no errors in `NetworkSettingsSection.tsx`.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/pages/servers/settings/NetworkSettingsSection.tsx
git commit -m "Add common services quick add section to firewall settings"
```

---

### Task 4: Add rule dialog preset mode

**Files:**
- Modify: `frontend/src/pages/servers/settings/NetworkSettingsSection.tsx`

- [ ] **Step 1: Clear the preset when the dialog closes**

Replace the Dialog opening tag (currently `<Dialog open={showAddRuleDialog} onOpenChange={setShowAddRuleDialog}>`):

```tsx
<Dialog
  open={showAddRuleDialog}
  onOpenChange={(open) => {
    setShowAddRuleDialog(open)
    if (!open) setActivePreset(null)
  }}
>
```

- [ ] **Step 2: Preset-aware title and description**

Replace the `DialogTitle` and `DialogDescription` contents:

```tsx
<DialogTitle>
  {activePreset
    ? `Allow ${activePreset.name} (${activePreset.port}/tcp)`
    : 'Add firewall rule'}
</DialogTitle>
<DialogDescription>
  {activePreset
    ? 'Database ports must be restricted to a trusted source IP.'
    : 'Allow traffic to a port, optionally restricted to a single source.'}
</DialogDescription>
```

- [ ] **Step 3: Lock port and protocol in preset mode**

Add `disabled={!!activePreset}` to the port `Input`:

```tsx
<Input
  id="rule-port"
  value={ruleForm.port}
  onChange={(e) => setRuleForm({ ...ruleForm, port: e.target.value })}
  placeholder="8080 or 3000:3005"
  disabled={!!activePreset}
/>
```

Add `disabled={!!activePreset}` to the protocol `Select`:

```tsx
<Select
  value={ruleForm.protocol}
  onValueChange={(value) => setRuleForm({ ...ruleForm, protocol: value as 'tcp' | 'udp' | 'both' })}
  disabled={!!activePreset}
>
```

- [ ] **Step 4: Required source field with warning in preset mode**

Replace the source field block (Label, Input) with:

```tsx
<div className="space-y-2">
  <Label htmlFor="rule-source">
    {activePreset ? 'Source' : 'Source (optional)'}
  </Label>
  <Input
    id="rule-source"
    value={ruleForm.source}
    onChange={(e) => setRuleForm({ ...ruleForm, source: e.target.value })}
    placeholder={
      activePreset
        ? '203.0.113.10 or 10.0.0.0/24'
        : 'leave blank for anywhere, or 10.0.0.0/24'
    }
  />
  {activePreset && (
    <p className="text-sm text-muted-foreground">
      Restrict database access to a trusted IP. Never open database ports to
      the whole internet.
    </p>
  )}
</div>
```

- [ ] **Step 5: Disable the submit button until a source is entered in preset mode**

Replace the Add Rule button:

```tsx
<Button
  onClick={handleAddRule}
  disabled={addingRule || (!!activePreset && !ruleForm.source.trim())}
>
  {addingRule && <LoadingSpinner size="sm" className="mr-2" />}
  Add Rule
</Button>
```

- [ ] **Step 6: Build and lint**

```bash
cd frontend && npm run build && npm run lint
```

Expected: both pass with no errors in `NetworkSettingsSection.tsx`.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/pages/servers/settings/NetworkSettingsSection.tsx
git commit -m "Add preset mode to firewall add rule dialog"
```

---

### Task 5: Manual UI verification

**Files:** none (verification only)

- [ ] **Step 1: Drive the UI**

Use the project `verify` skill (dev server, temp login user, Playwright) to open a server's Settings, Network section, against a test server with UFW installed and active. Verify:

1. The Common services block renders between the status toggle and the Rules table with the help text.
2. Ports already allowed (for example 22 is not a preset, but if 80 was added previously) show a check icon, the text "allowed", and a disabled button.
3. Clicking HTTP adds `80/tcp ALLOW IN Anywhere` and the button flips to the allowed state without a page reload.
4. Clicking MySQL opens the dialog titled "Allow MySQL (3306/tcp)" with port and protocol disabled and the Add Rule button disabled until a source IP is typed.
5. Submitting with source `10.0.0.5` creates `3306/tcp ALLOW IN 10.0.0.5`.
6. The generic Add rule button still opens the empty editable dialog.

If no test server is available, verify points 1, 4, and 6 visually and confirm request payloads in the browser network tab; full rule verification happens on the next fresh EC2 test instance.

- [ ] **Step 2: Report results**

Record what was verified and any deviations before merging.
