# Final Live Review Packet

This packet is for human review before the final #6/#9 live gates. Do not commit a completed packet. Do not paste tokens, API keys, passwords, or private repository credentials into this file.

## Review Status

- Reviewer:
- Review date:
- Approved live window:
- Approved API cost budget, `cost_budget_usd`:
- Approved artifact policy: `drafts_journal_usage` or `drafts_journal_usage_media`
- Completion expectation: `completion_ready=false` until all archived command plan, GitHub, soak, UX, summary, manifest, and redaction artifacts pass.

## GitHub Skill Store Gate

- User-approved official Skill Store coordinates:
- Repository:
- Skill path:
- Ref:
- Review policy: `quarantine`, `activate`, or `activate_pin`
- Activation/pin requested:
- GitHub token source: shell, WordPress Settings, or ignored env only
- Secret rule: `WP_AGENT_LIVE_GITHUB_TOKEN` must remain outside this packet, outside reviewed env files, outside design logs, outside lockfiles, and outside Git.

## Multi-Hour Soak Gate

- Run count:
- Timeout seconds:
- Soak seconds:
- Sample interval:
- Max usage rows:
- Approval phrase handling: set `WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=approve-multi-hour-soak` only after the reviewer approves this packet.
- Cost guard: live summary and manifest must record actual usage and estimated cost against `cost_budget_usd`.

## Content Source

- Source URL public HTTP(S):
- Expected source scope:
- Source safety rule: localhost, private, loopback, link-local, and reserved URLs must fail in command plan, strict preflight, and live harness before model work.

## Official Database

- Default database: `WP_AGENT_OFFICIAL_DB_DIR=/path/to/wp-agent/database/official-mysql`
- Throwaway database exception:
- Exception rule: non-default DB use requires separate approval and `WP_AGENT_FINAL_PREFLIGHT_ALLOW_NONDEFAULT_DB_DIR=1`.

## Cleanup/Rollback Policy

- cleanup/rollback policy:
- Temporary schedule handling:
- Temporary Skill handling:
- Daemon final state:
- Draft/media retention:
- Required live evidence: schedule paused, temporary Skill archived or rolled back, daemon stopped or heartbeat fresh, queue empty.

## Archive Requirements

- Archive root: `/path/to/wp-agent/design/test-logs/`
- Command plan evidence: `final-live-command-plan-YYYYMMDD.json`
- GitHub evidence: `final-live-github-skill-store-YYYYMMDD.json`
- Soak evidence: `final-live-editorial-daemon-soak-YYYYMMDD.json`
- UX evidence: `ui-playwright-evidence-contract-YYYYMMDD.md`
- Acceptance summary: `final-live-acceptance-summary-YYYYMMDD.md`
- Artifact manifest: `final-live-artifact-manifest-YYYYMMDD.json`
- Archive redaction report: `final-live-archive-redaction-YYYYMMDD.md`
- Required archive markers: `token_disclosed=false`, `remote_push=false`, `final-live-command-plan`, `ux_validation_before_manifest=true`, `summary_before_manifest=true`

## Execution Order

1. Update reviewed inputs from this approved packet.
2. Run command plan dry-run.
3. Run strict final preflight.
4. Start resident daemon.
5. Run GitHub live gate.
6. Run multi-hour soak gate.
7. Stop or confirm daemon state.
8. Run Git hygiene.
9. Archive UX evidence.
10. Generate acceptance summary.
11. Generate artifact manifest.
12. Run archive redaction.
13. Run completion gate.

Failure rule: keep `goals.md` as `状态：实施中`, keep #6/#9 as partial, and do not claim final completion until the completion gate reports `completion_ready=true` with valid artifacts.
