# Next-generation UI — implementation status

Design reference: [new-gen-ui.md](new-gen-ui.md).

Last updated: 2026-09-18. Work lives on branch `feature/new-gen-ui` (based on `919bc6e`). Commits: `a0b20c4` shell, list, overviews, motion foundation; second commit adds release-focused overview, inline connection test, URL state, and skeleton/not-found states.

## Current milestone

Phase 1: visual foundation, responsive shell, organization server list, resource overview polish, and motion/feedback foundation.

Representative screens are restyled and the motion foundation is in place, but several Phase 1 behaviors from the design document are still open (see "Phase 1 open items"). No screen has been visually verified in a browser yet; user screenshots have been the only visual reference.

## Change log

| Change | Status | Notes |
| --- | --- | --- |
| Repository and existing UI review | Complete | Existing routes and operation handlers inspected before any change |
| Semantic light/dark palette and shared controls | Implemented | Graphite/blue tokens, raised cards, section headings, focus ring, selection color, antialiased body text; card and input surfaces on `bg-card` |
| Compact header and contextual sidebar | Implemented | 56px/64px translucent header, 208px desktop rail, mobile drawer, parent navigation, scope labels, resource name, Settings separated by a divider, wide output views for terminal/logs/deployment detail, placeholder footer removed |
| Responsive settings layout | Implemented | Horizontal section tabs (scroll on small screens), 1040px content limit, label/control grid that stacks below `sm`, full-width controls |
| Server/application overview polish | Implemented | Shared resource header, direct New application button, "Applications" heading replaces "Recent sites", duplicate Deploy removed from the deployments card, monospace host and domain, "Connected" label for active servers |
| Searchable server list | Implemented | Name/address search, shared Radix Select status filter, live result count, error state with retry, skeleton loading, clear-filter empty state; server icon replaces initial-letter avatar; "Added X ago" shown from `xl` up |
| Whole-row links | Implemented | Server, application, deployment, and domain rows use a real link that covers the row (`.row-link` / `.row-cover`). Keyboard, middle-click, and full-row tap all work; row action menus sit above the cover; chevron hint slides on hover |
| Navigation terminology | Implemented | Scheduler → Scheduled tasks, Daemons → Processes, Domains → Domains & TLS across sidebar, headings, buttons, dialogs. Leftover: one dialog description in DaemonsPanel still says "daemon" |
| Global type and card scale | Implemented | Tailwind `xs`/`sm` reduced to 12px/14px; CardTitle reduced from 2xl to base (affects every card app-wide); large headings get −0.02em tracking and 1.15 leading |
| Content frame | Implemented | Main content limit 1440px (was 1152px); shell uses dynamic viewport height, only the main column scrolls; new destinations scroll to top |
| Motion and feedback foundation | Implemented | Easing and duration tokens (120/160/240ms); buttons, nav items, and theme toggles compress on pointer-down; page content fades up on route change; header grows an edge only once content is scrolled beneath it; theme switches ease over 240ms |
| Reduced motion, transparency, and contrast | Implemented | Reduced motion cross-fades entrances (translate/scale neutralized), keeps spinners slow, stops skeleton pulse and status pulse. Reduced transparency makes header and scrims solid. High contrast strengthens border and input tokens |
| Semantic status badge | Implemented | Dot plus text so state is never color alone; theme-aware tones; dot pulses only for in-progress states; optional human label ("Connected", "Deploy failed") |
| Dialog and drawer materials | Implemented | Scrim 40% black with backdrop blur (was 80% opaque); drawer opens 300ms / closes 200ms ease-out (was 500/300); dialogs ease-out |
| Shared avatar tint and dark-aware accents | Implemented | Single `getAvatarColor` in `lib/utils` replaces three copies; light-only tints replaced with alpha tints that read in both themes; success/failure counts use tabular numerals |
| Metrics card polish | Implemented | Bars animate width only on data change; "Updated HH:mm:ss" freshness label; progressbar roles and labels; tabular numerals |
| Accessibility details | Implemented | Skip-to-content link; focusable main region; every icon-only menu labeled; switcher check marks are labeled icons; settings tabs expose `aria-current` |
| Release-focused application overview | Implemented | Current release card (commit, branch, host, deployed, duration, Live badge), callout for a newer failed or running deployment, Visit button, Git provider badge links to settings, skeleton and not-found states |
| Server overview feedback and states | Implemented | Inline Test connection with result and time under the address; overflow menu holds Settings and Open terminal; skeleton and not-found states |
| URL-backed view state | Implemented | Settings sections (`?section=`) across all three scopes via `useSectionParam`; server list search and status (`?q=`, `?status=`) |
| Stale metrics | Implemented | Failed refresh keeps the last sample visible at reduced opacity with a stale notice and time |
| Help links | Implemented | Documentation and Report an issue in the user menu |

## Phase 1 open items

Checked against new-gen-ui.md on 2026-09-18. Section numbers refer to that document.

- [ ] Browser verification: baseline screenshots, light/dark, narrow/wide (§11 steps 1, 2, 6).
- [x] Application overview leads with the current release: commit, branch, result, domain, time (§7). A "Current release" card shows the latest successful deployment with a Live badge, deployed time, duration, and a callout when a newer deployment failed or is running.
- [x] Visit application action when a valid domain exists (§7). Uses the primary domain and its TLS flag to pick the scheme.
- [x] "Git provider not connected" links to the fix (§7). Badge reads "Connect a Git provider" and links to application settings.
- [x] Test connection with inline result feedback (§6). Outline button in the server header; result, time, and icon appear beneath the address. Hidden for local servers.
- [x] Metrics distinguish stale from error (§6). A failed refresh keeps the last sample on screen at reduced opacity with a "Stale, last sample HH:mm:ss" notice. Unavailable (never collected) still shows the error card.
- [x] Content-shaped skeletons on server and application overview initial loads (§8).
- [x] Designed not-found states for server and application (§8).
- [x] Settings sections linkable through URL state (§4). `?section=` via a shared `useSectionParam` hook; default section is kept out of the URL.
- [x] Server list search and status filter kept in the URL (§9). `?q=` and `?status=`, written with history replace so typing does not spam Back.
- [x] Compact help/docs menu (§4). Documentation and Report an issue links in the user menu, pointing at the GitHub README and issues.
- [x] Process dialog wording fixed.
- [ ] One icon family. Heroicons is imported in 24 files and Lucide in 16; the shell and ui/ primitives use Lucide. Consolidating on Lucide is a mechanical but wide change best done with a browser open.
- [ ] Frontend test runner and focused regression coverage for changed routing (§13). No runner is installed; adding one is a dependency decision.

## Fluid-interface review (2026-09-18)

The proposal was checked against Apple's fluid-interface guidance (response, interruptibility, spatial consistency, materials, typography, reduced motion, and the eight design principles).

Adopted and implemented: pointer-down feedback, 120 to 240ms transitions, size-specific tracking, tabular numerals, symmetric drawer paths, softened scrims, reduced-motion cross-fades, reduced-transparency and high-contrast fallbacks, status never by color alone, whole-row targets, metric freshness, eased theme switching.

Gaps found in the proposal:

- Motion is described as durations only. Anything a user can grab must also be interruptible. The mobile drawer has no drag-to-dismiss; a spring-driven drawer is a Phase 2 candidate and needs a spring library, since CSS transitions cannot be reversed mid-flight.
- Translucent chrome has nothing beneath it. The header sits outside the scroll container, so the blur is cosmetic. Page-level scrolling with a sticky header is deferred because terminal and log views depend on the main column being the fixed-height scroller.
- No guidance on theme switching. Implemented as an eased transition.
- The first reduced-motion rule removed all feedback (spinners, skeletons) instead of softening it. Replaced.

Deliberately not implemented: haptics and sound, momentum projection (no flickable surfaces), translucent sidebar (would stack a light material on another).

## Verification

| Check | Result |
| --- | --- |
| `npm run build` (tsc + Vite) | Passed |
| `npm run lint` | 0 errors, 18 pre-existing hook/fast-refresh warnings |
| `git diff --check` | Clean |
| Browser visual and interaction review | Not done. No backend on the default local address; browser automation was not approved |

Build notices: outdated Browserslist data and a bundle over 500 kB. Dependency updates and code splitting are out of scope for Phase 1.

Highest-value items for the first browser review:

- Status badge tones in dark mode.
- Header edge effect on scroll and translucency over the canvas.
- Row cover pattern on touch, including the action menu staying tappable.
- Drawer open/close feel at 300/200ms.
- Settings grid at phone width.

## Files touched

All committed on `feature/new-gen-ui`. No backend changes.

Foundation: `frontend/src/index.css`, `frontend/tailwind.config.js`, `frontend/src/lib/utils.ts`, `frontend/src/contexts/ThemeContext.tsx`

Shell: `frontend/src/components/Layout.tsx`, `ContextTabs.tsx`

UI primitives: `frontend/src/components/ui/button.tsx`, `card.tsx`, `input.tsx`, `select.tsx`, `dialog.tsx`, `alert-dialog.tsx`, `sheet.tsx`

Custom components: `frontend/src/components/custom/StatusBadge.tsx`, `ServerMetricsCard.tsx`, `frontend/src/components/DaemonsPanel.tsx`, `SchedulerPanel.tsx`

Pages: `frontend/src/pages/Servers.tsx`, `apps/AppOverview.tsx`, `apps/AppSettings.tsx`, `servers/ServerOverview.tsx`, `servers/ServerSettings.tsx`, `settings/Settings.tsx`

Hooks: `frontend/src/hooks/useSectionParam.ts` (new)

Documents: `new-gen-ui.md`, `new-gen-ui-status.md`

Open follow-ups:

- Confirm the minimum browser target. The row focus ring uses `:has()`, supported in all current evergreen browsers.
- Run the browser review listed above, now also covering the current release card and inline connection result.

## Remaining roadmap

- Phase 2: organization overview, organization/server application indexes, operational screens (deployments, deployment detail, domains, logs, terminal), Environment destination, searchable switching, gesture-driven drawer.
- Phase 3: software, databases, tasks/processes, settings separation, integrations, members, notifications, Trash.
- Phase 4: creation/onboarding/authentication polish, full state and accessibility review, performance review.

## Environment and limitations

- Local Node dependencies are present; build and lint run locally.
- No backend responded at `http://localhost:80`. Authenticated browser verification needs a running stack or isolated fixtures.
- No backend capabilities or infrastructure operations have been changed.
