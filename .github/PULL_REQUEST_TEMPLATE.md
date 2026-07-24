## Summary

<!-- What this PR changes, in 1–3 bullets. Focus on *why* over *what*. -->

## Test plan

<!-- How you verified this. Include the transport(s) exercised and any config used. -->
- [ ] `make lint` passes
- [ ] `make test` passes
- [ ]

## Screenshots / recordings

<!-- Required for any settings-page or other admin UI change. Delete this section if N/A. -->

## Checklist

- [ ] Added or updated tests when behavior changed
- [ ] Kept it lean — no new dependencies or upsells
- [ ] Secrets still never round-trip to the browser or into `wp lean-smtp status`
- [ ] Version bump matched across the plugin header, `LEAN_SMTP_VERSION`, and readme.txt `Stable tag`, with a `== Changelog ==` entry (release PRs only)
