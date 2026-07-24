# Firewall Quick Add Presets and Help Section

Date: 2026-07-24
Status: Approved

## Goal

Make it easy to open the ports a deployed server actually needs, directly from the server Network settings (Firewall/UFW section), and explain which ports matter and why. Today the panel only offers a free-form "Add rule" dialog, and a fresh server ends up with only SSH open, so deployed sites are unreachable until the user figures out which ports to allow.

## Scope

Frontend only. All changes live in `frontend/src/pages/servers/settings/NetworkSettingsSection.tsx`. The existing `firewallApi.addRule(serverId, { port, protocol, source })` endpoint is reused as is. No backend, API, or database changes.

## Design

### Common services block

A new block is rendered between the firewall status toggle and the Rules table, visible only when UFW is installed (same condition as the Rules table).

It contains:

1. Short help text explaining the essentials: websites served from this server need ports 80 and 443 open to anywhere (80 is also required for Let's Encrypt HTTP challenges). Database ports should only be opened to specific trusted IPs, because applications running on the server connect over localhost and do not need them exposed.
2. A row of preset buttons, all tcp:
   - HTTP, port 80
   - HTTPS, port 443
   - MySQL, port 3306
   - PostgreSQL, port 5432
   - Redis, port 6379

### Preset behavior

- Web presets (HTTP, HTTPS): one click calls `firewallApi.addRule` immediately with the port, protocol `tcp`, and `source: null` (anywhere). A loading state is shown on the clicked button while the request runs.
- Database presets (MySQL, PostgreSQL, Redis): clicking opens the existing Add rule dialog pre-filled with the preset port and protocol `tcp`, both read-only. The source field becomes required, with helper text: "Restrict database access to a trusted IP. Never open database ports to the whole internet." The Add button is disabled (or shows a validation error) until a source is entered.
- Already-allowed detection: a preset renders as "Allowed" with a check icon and is disabled when the current rules contain an IPv4 `ALLOW IN` rule whose `to` field matches `<port>/tcp` exactly. Port ranges and app profile rules are not matched; that is acceptable since the presets themselves only ever create exact `<port>/tcp` rules.

### Add rule dialog changes

The dialog gains an optional preset mode:

- Dialog state tracks an active preset (or none). Opening via "Add rule" keeps today's behavior unchanged.
- In preset mode the title reads "Allow <name> (<port>/tcp)", the port and protocol inputs are read-only, and the source input is required with the database warning helper text.
- Closing the dialog clears the preset so the generic flow is unaffected.

### Error handling

Same pattern as the rest of the panel: failures surface through `toast.error(getErrorMessage(...))`, success refreshes the rules list from the response payload and shows a success toast.

## Testing

Manual verification through the UI against a test server (fresh EC2 instance per the usual workflow): presets add the expected rules, already-open ports show as Allowed, database presets refuse to submit without a source, and the generic Add rule flow still works. There is no existing frontend test suite for this page, so no automated tests are added.
