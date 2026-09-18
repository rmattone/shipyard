# Next-generation UI — implementation status

Design reference: [new-gen-ui.md](new-gen-ui.md).

Last updated: 2026-09-18. All work below is uncommitted on top of `919bc6e`.

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

## Phase 1 open items

Checked against new-gen-ui.md on 2026-09-18. Section numbers refer to that document.

- [ ] Browser verification: baseline screenshots, light/dark, narrow/wide (§11 steps 1, 2, 6).
- [ ] Application overview leads with the current release: commit, branch, result, domain, time (§7).
- [ ] Visit application action when a valid domain exists (§7).
- [ ] "Git provider not connected" links to the fix (§7).
- [ ] Test connection with inline result feedback instead of a toast in an overflow menu (§6).
- [ ] Metrics distinguish stale and unavailable from loading and error (§6).
- [ ] Content-shaped skeletons on server and application overview initial loads (§8).
- [ ] Designed not-found states for server and application (§8).
- [ ] Settings sections linkable through URL state (§4).
- [ ] Server list search and status filter kept in the URL (§9).
- [ ] One icon family; Heroicons and Lucide currently coexist in 24 and 16 files (§3).
- [ ] Compact help/docs menu to replace the removed footer links (§4).
- [ ] Frontend test runner and focused regression coverage for changed routing (§13).

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

## Uncommitted work

23 frontend files modified, 2 untracked documents, no backend changes.

Foundation: `frontend/src/index.css`, `frontend/tailwind.config.js`, `frontend/src/lib/utils.ts`, `frontend/src/contexts/ThemeContext.tsx`

Shell: `frontend/src/components/Layout.tsx`, `ContextTabs.tsx`

UI primitives: `frontend/src/components/ui/button.tsx`, `card.tsx`, `input.tsx`, `select.tsx`, `dialog.tsx`, `alert-dialog.tsx`, `sheet.tsx`

Custom components: `frontend/src/components/custom/StatusBadge.tsx`, `ServerMetricsCard.tsx`, `frontend/src/components/DaemonsPanel.tsx`, `SchedulerPanel.tsx`

Pages: `frontend/src/pages/Servers.tsx`, `apps/AppOverview.tsx`, `apps/AppSettings.tsx`, `servers/ServerOverview.tsx`, `servers/ServerSettings.tsx`, `settings/Settings.tsx`

Untracked: `new-gen-ui.md`, `new-gen-ui-status.md`

Follow-ups before commit:

- Fix the remaining "daemon" wording in the new process dialog description.
- Confirm the minimum browser target. The row focus ring uses `:has()`, supported in all current evergreen browsers.
- Run the browser review listed above.

## Remaining roadmap

- Phase 2: organization overview, organization/server application indexes, operational screens (deployments, deployment detail, domains, logs, terminal), Environment destination, searchable switching, gesture-driven drawer.
- Phase 3: software, databases, tasks/processes, settings separation, integrations, members, notifications, Trash.
- Phase 4: creation/onboarding/authentication polish, full state and accessibility review, performance review.

## Environment and limitations

- Local Node dependencies are present; build and lint run locally.
- No backend responded at `http://localhost:80`. Authenticated browser verification needs a running stack or isolated fixtures.
- No backend capabilities or infrastructure operations have been changed.
