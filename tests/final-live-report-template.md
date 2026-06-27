# Final Live Acceptance Report

Status: draft until the official GitHub Skill Store gate and the multi-hour daemon soak gate both pass with user-approved inputs. Do not mark `goals.md` complete from this template alone.

## Review Metadata

- Date:
- Operator:
- Git HEAD:
- Official stack: `docker-compose.official.yml`, project `wp-agent-official`
- WordPress URL: `http://localhost:12910`
- Official database dir: `/path/to/wp-agent/database/official-mysql`
- Runtime root:
- Local Git state: `remote_push=false`
- Reviewed input source: `tests/final-live-inputs.example.env` with `owner/repo` and `skills/example` replaced before live execution
- Review packet source: `final-live-review-packet-YYYYMMDD.md`, ignored by Git and not tracked

## Pre-Live Gates

- `php tests/final-no-live-acceptance-contract.php`:
- `php tests/final-live-report-artifact-contract.php`:
- `php tests/final-live-artifact-manifest-build-contract.php`:
- `php tests/final-live-artifact-manifest-contract.php`:
- `php tests/final-live-archive-redaction-contract.php`:
- `php tests/ui-playwright-evidence-contract.php`:
- Strict preflight scope:
- Strict preflight result: `ready=true`
- No-live aggregate result: `live_network_calls=false`, `ai_gateway_calls=false`, `github_calls=false`
- Review packet status: `packet_ready=true`, `path_ignored_by_git=true`, `path_tracked_by_git=false`
- Command plan readiness: `commands_executable=true`, `ready_for_live_execution=true`, `review_packet_ready=true`, `review_packet_env_consistent=true`
- Command plan evidence path: `/path/to/wp-agent/design/test-logs/final-live-command-plan-YYYYMMDD.json`
- UX evidence result: `ux_quality_gate=true`, `chat_stop_playwright=true`, `chat_queue_status_playwright=true`, `chat_stop_availability_playwright=true`, `composer_unlocked_guard=true`
- Command plan artifact order: `ux_validation_before_manifest=true`, `summary_before_manifest=true`
- Secret redaction result: `token_disclosed=false`
- Archive redaction result: `token_disclosed=false`, `raw_secret_hits=0`
- Archive redaction evidence path: `/path/to/wp-agent/design/test-logs/final-live-archive-redaction-YYYYMMDD.md`
- Preflight evidence path: `/path/to/wp-agent/design/test-logs/final-acceptance-preflight-YYYYMMDD.json`

## GitHub Skill Store Gate (#6)

- Repository:
- Ref:
- Skill path:
- Review policy:
- Quarantine ID:
- Skill slug/name/version:
- File count:
- Lock path under private runtime root: `lock_under_runtime_root=true`
- Token state: `has_token=true|false`, `token_disclosed=false`
- Activation state: `activated=true|false`, `pinned=true|false`
- Evidence path: `/path/to/wp-agent/design/test-logs/final-live-github-skill-store-YYYYMMDD.json`
- Result:

## Multi-Hour Daemon Soak Gate (#9)

- Approval flag: `WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED=1`
- Approval phrase: `WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=approve-multi-hour-soak`
- Cost budget:
- Artifact policy:
- Public source URL:
- Requested run count:
- Timeout seconds:
- Soak seconds:
- Sample interval:
- Max usage rows:
- Resident daemon command:
- Schedule ID:
- Run IDs:
- Post IDs:
- Cost before/after/added:
- Usage rows added:
- Journal rows:
- Heartbeat max age:
- Elapsed seconds:
- Soak completed: `soak_completed=true`
- Approval phrase confirmed: `approval_phrase_confirmed=true`
- Memory summary:
- Cleanup state: schedule paused, temporary Skill archived, daemon stopped or intentionally left running
- Evidence path: `/path/to/wp-agent/design/test-logs/final-live-editorial-daemon-soak-YYYYMMDD.json`
- Result:

## Archived Artifacts

- `/path/to/wp-agent/design/test-logs/final-no-live-acceptance-contract-YYYYMMDD.md`
- `/path/to/wp-agent/design/test-logs/final-acceptance-preflight-YYYYMMDD.json`
- `/path/to/wp-agent/design/test-logs/final-live-command-plan-YYYYMMDD.json`
- `/path/to/wp-agent/design/test-logs/final-live-github-skill-store-YYYYMMDD.json`
- `/path/to/wp-agent/design/test-logs/final-live-editorial-daemon-soak-YYYYMMDD.json`
- `/path/to/wp-agent/design/test-logs/git-hygiene-contract-YYYYMMDD.md`
- `/path/to/wp-agent/design/test-logs/ui-playwright-evidence-contract-YYYYMMDD.md`
- `/path/to/wp-agent/design/test-logs/final-live-acceptance-summary-YYYYMMDD.md`
- `/path/to/wp-agent/design/test-logs/final-live-artifact-manifest-YYYYMMDD.json`
- `/path/to/wp-agent/design/test-logs/final-live-archive-redaction-YYYYMMDD.md`

## Acceptance Summary

- Summary evidence path: `/path/to/wp-agent/design/test-logs/final-live-acceptance-summary-YYYYMMDD.md`
- Required markers: `/path/to/wp-agent/database/official-mysql`, `remote_push=false`, `token_disclosed=false`, `completion_ready=true`, `packet_ready=true`, `ready_for_live_execution=true`, `review_packet_ready=true`, `review_packet_env_consistent=true`, `chat_queue_status_playwright=true`, `chat_stop_availability_playwright=true`, `composer_unlocked_guard=true`, `final-live-command-plan`
- Required artifact references: `ui-playwright-evidence-contract`, `final-live-command-plan`, `final-live-github-skill-store`, `final-live-editorial-daemon-soak`, `final-live-archive-redaction`
- Required acceptance rows: `#6`, `#9`

## Artifact Manifest

- Template source: `tests/final-live-artifact-manifest-template.json`
- Builder command: `WP_AGENT_FINAL_LIVE_MANIFEST_WRITE=1 php tests/final-live-artifact-manifest-build.php path/to/reviewed.env /path/to/wp-agent/design/test-logs path/to/final-live-review-packet-YYYYMMDD.md`
- Manifest evidence path: `/path/to/wp-agent/design/test-logs/final-live-artifact-manifest-YYYYMMDD.json`
- Manifest contract: `php tests/final-live-artifact-manifest-contract.php`
- Required contents: artifact paths, sha256 hashes, local Git HEAD, `remote_push=false`, official DB dir, completed review packet source, archived command plan path/hash, `ready_for_live_execution=true`, `review_packet_ready=true`, `review_packet_env_consistent=true`, command plan result, `ux_validation_before_manifest=true`, `summary_before_manifest=true`, archive redaction report, completion gate result, and `token_disclosed=false`

## Completion Rule

Update the acceptance matrix only after the archived artifacts prove #6 and #9 passed in the official WordPress container with the approved database path and without secret disclosure.
