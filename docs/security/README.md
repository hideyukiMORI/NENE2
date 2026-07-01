# Security

Security posture, policy, and assessment records for NENE2.

## Policy and process

- **[ADR 0011 — Security review policy](../adr/0011-security-review-policy.md)** —
  when and how security review happens.
- **[Middleware & security self-review checklist](../review/middleware-security.md)** —
  the per-change checklist for auth, logging, CORS, and headers.

## Assessments

Point-in-time security assessments (black-box penetration tests plus code
review). Each is a snapshot of the version tested.

| Date | Version | Report | Result |
|------|---------|--------|--------|
| 2026-07-02 | v1.5.332 | [Post-remediation verification](2026-07-02-post-fix-assessment.md) | 0 exposed findings; all prior issues regression-verified |

## Reporting a vulnerability

Security issues are tracked as GitHub issues in this repository. For a
maintainer-run assessment, follow the ATK/verification methodology described in
the latest report above: exercise the running application in a disposable
container, never against production data or credentials.
