# ShipYard — Next-generation UI

Status: design proposal and implementation roadmap. No application changes are made by this document.

## 1. Product direction

Make ShipYard feel like a premium infrastructure cockpit: deep graphite surfaces, crisp typography, a distinctive blue accent, readable operational data, and fast navigation.

The interface should answer three questions immediately:

1. Where am I: organization, server, or application?
2. What needs my attention?
3. What can I do next?

“Sexy” should come from composition, precision, responsive interactions, and useful information. Visual polish must extend through settings, dialogs, empty states, and deployment output—not just the dashboard.

This proposal follows a review of the frontend source and route structure. It is not a completed live-browser or accessibility audit. New data-dependent features below require confirmation of backend support.

## 2. Problems to address

- The current header consumes substantial vertical space.
- The same content width is used for forms, lists, terminal sessions, and logs despite their different needs.
- Cards and the page background share colors, weakening visual depth.
- Organization navigation exposes only Servers and Settings; applications are buried under servers.
- Personal account, organization, and installation settings share one settings menu.
- Context changes between organization, server, and application are not visually strong enough.
- Naming alternates between sites, applications, and apps.
- Actions repeat, including Deploy on the application overview.
- Some menus contain only one action, such as the New site menu.
- Settings use a fixed-width sidebar with a special wide-screen positioning rule, complicating responsive consistency.
- Generic cards and headings give different workflows similar visual weight.
- The persistent footer takes working space and contains placeholder links.

## 3. Visual system

### Color and depth

I would introduce semantic design tokens and remove scattered hard-coded surface colors.

| Role | Initial dark-theme direction | Usage |
| --- | --- | --- |
| Canvas | Deep graphite, approximately `#0D1117` | Main background |
| Surface | Approximately `#151B23` | Panels and navigation |
| Raised surface | Approximately `#1C2430` | Menus, dialogs, hovered surfaces |
| Border | Approximately `#2A3441` | Subtle separation |
| Main text | Soft white, approximately `#F0F3F8` | Titles and primary content |
| Secondary text | Cool gray, approximately `#A2ADBD` | Supporting information |
| Brand/action | Electric blue, approximately `#4D8DFF` | Primary actions, focus, selected navigation |
| Success | Green | Successful operations and verified healthy states |
| Warning | Amber | Attention and incomplete setup |
| Error | Red | Failures and destructive actions |

These are starting values, not validated final contrast combinations. Check foreground/background pairs in both themes before adopting them.

- Give light mode equally intentional surfaces, borders, and contrast.
- Use color together with text or icons; never communicate status through color alone.
- Keep decorative gradients restrained, primarily in onboarding or brand artwork.
- Use shadows mainly for elevation in menus, drawers, and dialogs.
- Distinguish default, hover, selected, disabled, and keyboard-focus states.

Reference: [Vercel Geist colors](https://vercel.com/geist/colors) demonstrates a useful separation of backgrounds, component states, borders, and text. Use the principle while giving ShipYard its own identity.

### Typography, spacing, and icons

- Use one clean sans-serif family for the interface and one monospace family for commands, IPs, paths, branches, and deployment IDs.
- Establish a small type scale: prominent resource title, section heading, body, and supporting label.
- Favor medium and semibold weights over bold text everywhere.
- Use tabular numerals for metrics and durations.
- Standardize spacing on a small scale, such as 4, 8, 12, 16, 24, and 32px.
- Use approximately 8–12px corner radii, with smaller radii for compact controls.
- Consolidate the current icon usage into one consistent family and size system.
- Truncate long resource names carefully and offer access to the complete value.

### Motion

- Use short, approximately 120–180ms transitions for hover, selection, drawers, and feedback.
- Animate progress only when it reflects actual work.
- Avoid chart redraws and layout shifts on every refresh.
- Respect reduced-motion preferences.

## 4. Application shell and navigation

### Shared shell

I would replace the tall header and contextual tab strip with a compact top bar and consistent contextual sidebar.

The top bar contains:

- ShipYard identity and organization switcher.
- Organization → server → application breadcrumbs as applicable.
- Searchable resource switching.
- Global search / command palette entry point.
- User menu with account settings, appearance, and sign out.

The sidebar changes with the active scope while keeping its position, interaction, and styling consistent. Provide an obvious route back to the parent server or organization.

| Organization | Server | Application |
| --- | --- | --- |
| Overview | Overview | Overview |
| Servers | Applications | Deployments |
| Applications | Software | Domains & TLS |
| Integrations | Databases | Environment |
| Members | Scheduled tasks | Logs |
| Notifications | Processes | Scheduled tasks |
| Settings | Terminal | Processes |
| | Settings | Settings |

### Navigation behavior

- Preserve the current organization scope when navigating and searching.
- Make settings subsections linkable through routes or URL state so refresh and Back work predictably.
- Keep personal account controls separate from organization controls.
- Put installation administration in a separate, permission-appropriate area.
- Retain compatibility for existing URLs when routes move.
- Use the command palette for navigation and safe discovery; mutating operations retain their normal action flow.
- Remove the persistent copyright footer from the working interface. Relocate real documentation/help links into a compact menu.

### Layout and responsive behavior

- Use a comfortable reading width for forms.
- Allow resource lists and dashboards to use more available width.
- Let logs and terminal sessions fill the working area.
- Give resource headers a consistent title, metadata, status, and action arrangement.
- On smaller screens, use a navigation drawer and collapse secondary metadata.
- Allow action groups to wrap cleanly.
- Replace wide settings sidebars with a compact section selector where needed.
- Avoid page-level horizontal scrolling; allow it inside code and genuinely wide tables.

## 5. Organization screens

### Overview — new landing page

I would make the organization home useful for daily operations.

- Summarize server and application counts.
- Surface failed deployments, disconnected resources, and other supported attention states.
- Show recent deployment activity across applications.
- Provide direct links to affected resources.
- Offer New server and New application with clear action priority.
- Show freshness for operational information.
- Avoid invented health scores or activity feeds without real data.

### Servers

- Use searchable, filterable rows with server identity, address, status, and application count.
- Add resource metrics where available without forcing a separate request per row.
- Make creation date secondary to current operational state.
- Keep row actions predictable: open, settings, and relevant supported operations.
- Use proper links for resource navigation so keyboard access and opening in a new tab work.
- Offer an intentional first-server empty state.

### Applications — new organization-wide index

- List applications across the organization with their parent server.
- Show runtime, domain, repository/branch, and deployment state where available.
- Support search and useful server/status filters.
- Make it possible to reach an application without first locating its server.
- Keep actual application health separate from deployment status.

### Integrations

- Move Source Control into a clearly named integrations area.
- Show provider identity, configured host, connection state, and linked applications.
- Provide a clear connect flow with appropriate authentication guidance.
- Keep credential editing deliberate and masked.
- Show connection-test results close to the related connection.
- Keep provider details and linked applications easy to scan.

### Members

- Use a clean member table with readable roles and permitted actions.
- Separate active members from pending invitations.
- Keep Invite member prominent without dedicating a large permanent form to it.
- Show invitation state and supported invitation actions clearly.
- Explain permission limitations in context.

### Notifications

- Use compact channel rows showing type, destination, subscribed events, and enabled state.
- Provide a test action and show its result beside the channel.
- Use a drawer for channel creation and editing where the form fits comfortably.
- Mask tokens and avoid exposing secrets in summaries.
- Make event subscriptions easy to compare.

### Organization settings and Trash

- Keep organization identity and preferences in General.
- Clearly separate deletion from everyday settings.
- Make Trash understandable: what was removed, which actions restore it, and which permanently remove it.
- Describe supported restore/deletion consequences accurately.

### Personal account

- Move profile, password, sessions, and appearance into the user menu's account area.
- Group security-related controls together.
- Give session actions clear progress and completion feedback.

### Installation administration

- Separate system updates and system information from organization settings.
- Respect existing authorization rules.
- Show installed version, update availability, update progress, and actual results when supported.

## 6. Server screens

### Overview

- Use a compact identity header: server name, address, connection state, and primary action.
- Show CPU, memory, and disk trends with timestamps and clear units.
- Distinguish disconnected, stale, loading, and unavailable metric states.
- Surface application deployment issues without treating them as proof of server failure.
- Provide a short application summary linking to the full Applications screen.
- Standardize New application and make it a direct action.
- Keep Test connection accessible with inline result feedback.

### Applications

- Create a dedicated searchable application list within the server.
- Show runtime, domain, branch, tags, and deployment state where available.
- Retain useful tag filtering and Import existing.
- Use the same resource-list patterns as the organization-wide index.
- Replace “Recent sites” and “New site” with consistent application terminology.

### Software

- Clearly separate installed software from available packages.
- Show installed versions and supported service state.
- Give installation progress and errors a stable, visible location.
- Offer version selection only where supported.
- Explain the effect of service actions before execution when the effect is material.
- Keep useful installation output accessible without overwhelming the package list.

### Databases

- Present connections with engine, endpoint, and supported connectivity state.
- Distinguish detected database services from configured connections.
- Within connection details, group Databases, Users & permissions, and Restore history.
- Make managed users versus other server users explicit.
- Keep credentials masked with deliberate reveal/copy controls.
- Make restore progress, destination, and consequences clear.
- Preserve existing restore and permission behavior while improving presentation.

### Scheduled tasks

- Rename Scheduler to Scheduled tasks throughout navigation and headings.
- Show command, readable schedule, execution user, and enabled state.
- Keep cron syntax available alongside a human-readable explanation.
- Add last/next execution only if reliable data exists or is implemented separately.
- Preserve framework presets and custom scheduling.
- Use consistent create/edit forms with validation near the field.

### Processes

- Rename Daemons to Processes in user-facing navigation.
- Show command, directory, execution user, configured process count, and runtime state.
- Place start/stop/restart controls beside the relevant process.
- Distinguish configured state from observed runtime state.
- Keep queue-worker presets and advanced configuration available.
- Reuse this pattern for application processes.

### Terminal

- Fill the available working width and height.
- Use a slim toolbar showing server, user, and connection state.
- Make connect, reconnect, and disconnect behavior understandable.
- Preserve focus and terminal keyboard interactions.
- Keep terminal scroll behavior independent of the surrounding page.
- Design disconnected and unavailable states as carefully as the connected session.

### Settings

Use consistent sections for General, Users, SSH, Network, Tags, and Danger Zone.

- General: readable identity and connection configuration.
- Users: compact user list and focused creation flow.
- SSH: separate keys from hardening controls; show pending/result states accurately.
- Network: readable firewall rules with source, port, protocol, and action.
- Tags: compact editing with color preview and readable labels.
- Danger Zone: isolated destructive actions with precise scope and existing safeguards.

## 7. Application screens

### Overview

- Make the current release the focal point: commit, branch, deployment result, domain, and time.
- Keep one dominant Deploy action in the resource header.
- Present recent deployments as a compact supporting history.
- Show repository and runtime details in a quiet metadata area.
- Surface missing Git configuration with a direct path to resolution.
- Distinguish “deployment succeeded” from “application is healthy.”
- Keep Visit application available when a valid domain exists.

### Deployments

- Use a scannable history with status, commit, branch, duration, and current-release marker.
- Make running and failed deployments easy to identify.
- Clearly distinguish deployment history from releases currently retained on the server.
- Place rollback on eligible releases with accurate explanation of its effect.
- Preserve existing deployment concurrency protections.

### Deployment detail

- Give output most of the working area.
- Use compact metadata for status, commit, branch, start time, and duration.
- Support readable timestamps, search, copy, and output navigation where practical.
- Preserve the user's reading position while output updates; offer an explicit follow-output mode.
- Add stage progress only if the deployment engine exposes meaningful stages.
- Highlight actual failure output without inventing a diagnosis.

### Domains & TLS

- Combine domain and related certificate information into a coherent view.
- Show primary-domain designation and supported certificate status/expiry.
- Keep add-domain and certificate actions easy to discover.
- Show DNS verification only when a real check exists.
- Keep advanced Nginx configuration available through a clearly labeled action.
- Explain pending operations and actionable failures beside the affected domain.

### Environment — promoted from Settings

- Provide a key/value editor with masked values, search, and bulk paste.
- Retain a raw `.env` editing mode for advanced users.
- Preserve comments, ordering, quoting, multiline values, and duplicate-key behavior when switching representations; do not silently rewrite unsupported syntax.
- Use a proper editor experience for raw content.
- Show unsaved changes and explicit Save/Discard actions.
- Explain whether saving updates stored configuration, writes to the server, or requires deployment, based on actual behavior.
- Preserve existing reveal and authorization protections.
- Avoid rendering secret values into summaries, notifications, or logs.

### Logs

- Replace the oversized configuration area with a compact toolbar.
- Keep file selection, search, line count, refresh interval, and manual refresh accessible.
- Use most of the available space for output.
- Distinguish polling from a true live stream in labels.
- Preserve reading position during refresh and provide follow-output behavior where appropriate.
- Make loading, empty files, missing files, and request errors distinguishable.

### Scheduled tasks and Processes

- Reuse the server-level visual and interaction patterns.
- Clearly identify application scope and relevant execution directory/user.
- Retain application/framework presets.
- Avoid implying that application actions affect every process or task on the server.

### Settings

- General: identity, runtime, paths, and supported application configuration.
- Repository: provider, repository, and branch configuration grouped together.
- Deployment: script and supported deployment options, with a proper code editor.
- Webhooks: endpoint, masked secret, copy actions, and concise setup guidance.
- Danger Zone: isolated deletion controls and accurate consequences.
- Keep deployment configuration distinct from deployment history through naming and placement.

## 8. Creation, authentication, and secondary states

### Server creation

- Clarify local versus remote connection choices early.
- Group identity, connection, and authentication fields.
- Show connection validation feedback close to the relevant configuration.
- Reveal advanced fields progressively without hiding required setup.
- End with an actionable server overview or setup state.

### Application creation

- Use clear stages: application/runtime, source, deployment configuration, and review where useful.
- Preserve form input between stages.
- Make repository search and branch selection feel integrated.
- Keep manual repository entry available.
- Clearly distinguish application creation from its first deployment.
- Move webhook details into an intentional completion/setup state.

### Login, invitations, and organization creation

- Apply the same typography, surfaces, and branding as the authenticated app.
- Keep login focused with clear validation and submission feedback.
- Make invitation context explicit: organization and expected next step.
- Provide clear expired/invalid invitation states based on supported responses.
- Make first-organization onboarding feel intentional.

### Empty, loading, and error states

- Use content-shaped skeletons for initial page loads.
- Preserve visible content during background refresh where safe.
- Give each empty state a relevant explanation and next action.
- Keep permission-denied, unavailable, not-found, and empty states distinct.
- Present recoverable failures with a relevant retry action.
- Avoid success notifications that hide a continuing or failed background operation.

## 9. Shared interaction rules

- One dominant action per screen; secondary actions use quieter styling.
- Use direct buttons for single actions instead of one-item dropdowns.
- Use drawers for short contextual edits, pages for complex workflows, and dialogs for focused decisions.
- Keep save behavior consistent, with unsaved-changes feedback where relevant.
- Maintain existing authorization and destructive-action protections.
- Use inline feedback for contextual outcomes and toasts for supplemental confirmation.
- Make long-running operations visible after a dialog closes when the operation continues.
- Keep button labels specific: New application, Save changes, Test connection.
- Provide labels for icon-only actions, keyboard focus, sensible focus restoration, and touch-friendly targets.
- Retain useful filters and view state in URLs where appropriate.
- Avoid optimistic success for infrastructure actions until the relevant result is known.

## 10. Implementation approach

### Reuse the current foundation

I would retain React, Tailwind, the existing Radix-based primitives, and the current API layer unless a concrete requirement justifies a change. The redesign does not require a framework migration.

Primary implementation touchpoints:

- `frontend/src/index.css`: semantic tokens, typography, surfaces, focus, and motion.
- `frontend/src/components/Layout.tsx`: shared shell and responsive layout.
- `frontend/src/components/ContextTabs.tsx`: replace or adapt contextual navigation.
- `frontend/src/contexts/NavigationContext.tsx`: scope and resource switching.
- `frontend/src/App.tsx`: new destinations, settings deep links, and compatibility routes.
- `frontend/src/components/ui/`: consistent primitive styling and states.
- `frontend/src/components/custom/`: shared operational display patterns.
- `frontend/src/components/SchedulerPanel.tsx` and `DaemonsPanel.tsx`: shared task/process redesign.
- `frontend/src/pages/`: screen-specific composition and behavior.

### Shared components to establish

- Resource header with identity, metadata, status, and actions.
- Context sidebar and searchable resource switcher.
- Search/filter toolbar and consistent resource table/list.
- Metric panel with trend, units, timestamp, and stale/unavailable state.
- Status indicator with accessible label and semantic color.
- Settings section with description, controls, and save feedback.
- Empty state, error state, and content skeleton patterns.
- Code/log viewer shell and masked-value control.
- Consistent drawer, dialog, and operation-progress presentation.

Avoid building a large abstract component system before validating these patterns on real screens.

## 11. Delivery phases

### Phase 1 — establish the visual direction

1. Run the current app and inspect representative real states and viewport sizes.
2. Capture baseline screenshots for comparison.
3. Implement tokens, typography, and core controls.
4. Build the compact shell and contextual navigation.
5. Redesign organization Servers, Server overview, and Application overview as representative screens.
6. Verify light/dark themes and narrow/wide layouts before extending the system.

Outcome: the three scopes look coherent and the visual direction is concrete.

### Phase 2 — navigation and operational screens

1. Introduce the organization-wide Applications index and server Applications destination.
2. Add an organization overview using available data.
3. Redesign deployments, deployment detail, domains, logs, and terminal.
4. Promote Environment to its own application destination.
5. Add searchable switching and navigation search within supported scope.

Outcome: common daily work becomes faster and more legible.

### Phase 3 — management and settings

1. Redesign software and database workflows.
2. Apply shared Scheduled tasks and Processes patterns.
3. Reorganize organization, server, and application settings.
4. Separate Account and installation administration.
5. Improve integrations, members, notifications, and Trash.
6. Preserve old links and verify authorization throughout.

Outcome: secondary screens match the quality of the main workflows.

### Phase 4 — complete the experience

1. Polish server/application creation, login, invitations, and onboarding.
2. Complete empty, error, loading, disabled, and long-running operation states.
3. Verify keyboard, touch, reduced-motion, contrast, and responsive behavior.
4. Remove obsolete styles, duplicate actions, inconsistent names, and placeholder links.
5. Check performance with realistic list sizes and output volumes.

Outcome: the redesign holds together throughout the product.

## 12. Backend and behavior boundaries

The following should be validated separately from visual work:

| Proposed capability | Dependency or constraint |
| --- | --- |
| Organization activity and attention overview | Aggregated, organization-scoped data and permissions |
| Organization-wide application list | Existing listing API coverage, filtering, and pagination |
| Server metrics in list rows | Efficient metric retrieval, freshness, and unavailable states |
| Actual application health | A real health signal; deployment success is insufficient |
| Task execution history / next run | Execution records and reliable schedule/timezone calculation |
| Deployment stage progress | Structured stage events from deployment execution |
| Domain/DNS verification | Actual verification support and pending/error responses |
| Streaming logs | A streaming transport; polling must remain labeled accurately |
| Environment table/raw conversion | Lossless handling of supported `.env` syntax |
| Global search | Scope-aware data access and scalable search behavior |

Deliver visual improvements using real existing fields first. Treat any missing capability as an explicit functional addition, not as decorative mock data in production.

## 13. Verification and acceptance criteria

- Every existing tab and action remains reachable or has an intentional replacement.
- Organization, server, and application scope is always clear.
- Personal and installation settings are separated from organization settings.
- All primary screens use the shared visual system in both themes.
- Navigation works with refresh, Back, direct links, and existing bookmarked routes.
- No layout requires page-level horizontal scrolling at supported viewport sizes.
- Logs and terminal make effective use of the working area.
- Keyboard users can navigate, operate menus/dialogs, and identify focus.
- Status is understandable without relying on color.
- Secret visibility and permission boundaries are preserved.
- Deployment, restore, firewall, process, and deletion actions retain their existing safeguards and accurate result handling.
- Empty, loading, failure, stale, and permission-limited states are reviewed alongside successful states.
- Build and relevant existing checks pass; add focused regression coverage for changed routing or behavior.
- Complete a browser smoke test with representative data and compare screenshots to the baseline.

## 14. Recommended first milestone

Start with the shared shell, server overview, and application overview, alongside the organization server list. These screens establish navigation, resource identity, visual hierarchy, status language, and action placement.

Once these patterns work well with real data and responsive layouts, extend them through every tab, settings section, dialog, and creation flow using the phases above.
