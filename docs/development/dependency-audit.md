# Dependency audit

How NENE2 checks its dependencies for known vulnerabilities, and how an exception is written
when a fix is genuinely unreachable.

## Why this exists

NENE2 is the framework the other products build on, so a hole here is not one project's hole.
Until 2026-08, this repository had no npm dependency audit at all: no `audit-ci.jsonc`, no
`npm audit` in any workflow, and no `audit` script. An empty allowlist looked like strictness;
it was absence.

Two properties of the setup make that easy to miss, and both are addressed here:

- **No workflow ran on `push: main`, and none was scheduled.** Every check fired on pull
  requests only, so after a merge nothing was ever re-evaluated. An advisory published against
  an already-locked version would never surface from CI.
- **There are two npm trees**, and auditing the wrong one reports nothing while looking green.

## What runs

| Command | Tree | Config |
| --- | --- | --- |
| `composer audit --no-interaction` | PHP | — |
| `npm run audit` | root (docs toolchain) | `audit-ci.jsonc` |
| `npm run audit --prefix frontend` | frontend (SPA starter) | `frontend/audit-ci.jsonc` |

In CI: `composer audit` is its own step in `backend.yml`; the two npm audits are their own
steps in the `npm-audit` job of `frontend.yml`.

**Each audit is a separate step on purpose.** Folding them together — or into `npm run check` —
would make the job log unable to show which one actually ran, and a check that leaves no trace
cannot be distinguished from a check that is missing. For the same reason `audit` is not part
of `check`: a developer running `npm run check` locally should not have a network-dependent
advisory lookup silently attached to it.

### The two npm trees

|  | root | `frontend/` |
| --- | --- | --- |
| Purpose | builds the documentation site (`vitepress`) | the SPA starter |
| Shipped to consumers? | no | no (it is a starter to copy, not a dependency) |
| Open advisories (2026-08-22) | all of them | none |
| Allowlist | one entry, expiring | empty |

The root tree's gate makes an existing hole visible. The frontend tree's gate reports nothing
today — **that is the intended state, not a reason to drop it.** It is what stops the frontend
tree from falling behind unnoticed; a gate that only exists where problems are already known
cannot catch the next one.

### What is actually distributed

NENE2 ships as the Composer package `hideyukimori/nene2` (`composer.json`: `type: library`,
autoload `psr-4` `Nene2\` → `src/`). Consumers install PHP sources. No npm dependency from
either tree reaches them.

That bounds *who* is exposed by an npm advisory here — the maintainers of this repository — but
it does not make one harmless, and it is not a reason to skip the audit. It is the reason an
unreachable npm fix can be allowlisted with an argument instead of blocking a release.

## Weekly re-run

Both workflows run on `schedule: '30 1 * * 1'` (Monday 01:30 UTC / 10:30 JST). The minute is
allocated per repository across the fleet so runs do not stampede on the same minute.

The schedule re-runs the *existing* CI rather than a separate audit-only job, so the weekly
signal covers the same checks a pull request gets.

> **Known limitation, unsolved.** GitHub auto-disables scheduled workflows after 60 days of
> repository inactivity. A dormant repository — the one most in need of this check — is exactly
> where it stops firing, silently. There is no in-repo fix; detecting a stale last-run date
> needs an external poller at the fleet level. The comments saying so in the workflows are not
> stale notes to be tidied away.

## Failure notification

Each workflow has a `notify-failure` job that opens (or comments on) an issue when a **scheduled**
run fails. It deliberately does not fire on pull requests: a red check on a PR already has
someone looking at it, and filing an issue for it produces the duplicates that make people stop
reading notifications.

Because it only fires on `schedule`, a pull request cannot demonstrate that it works. Use the
`simulate_failure` input on `workflow_dispatch` to rehearse the path and watch the issue appear.

## 🔴 Verify the gate fails

A gate that cannot fail is worse than no gate, because it is mistaken for protection.

Adding or changing an audit gate is not finished until the failing path has been observed at
least once:

1. **The audit itself** — temporarily remove the allowlist entry, run the audit, and confirm it
   exits non-zero and names the advisory. Restore the entry.
2. **The notification** — dispatch the workflow with `simulate_failure: true` and confirm the
   issue is created (and that a second run comments on the same issue rather than opening a
   duplicate).

"The step is present in the YAML" is not evidence that it runs, and "CI is green" is not
evidence that it would go red.

## Writing an exception

`allowlist` is per advisory (GHSA id), never per severity — the level is not lowered. Anything
high or critical that is not listed fails the build.

**Prefer the fix.** Reach for an exception only when the fix is unreachable, and say why with a
measurement rather than an assumption.

An entry must carry, in the config file next to it:

1. the advisory id;
2. why it does not apply to **this** tree, measured — commands and outputs, not reasoning from
   the package name;
3. an expiry date;
4. what removes the need for it.

### Write removal conditions as dependency facts, not version numbers

**Advisory ranges move.** Measured in this fleet on 2026-08-22: the vite advisory's own numbers
had shifted since they were last written down (`first_patched` 6.4.2 → 6.4.3, the vulnerable
range widened to `<= 6.4.2`) while the conclusion was unchanged. A removal condition phrased as
"upgrade to 6.4.2" would have quietly become wrong without anything failing.

So write the condition as the dependency fact that would actually change:

```
Removed by: vitepress publishing a release whose `dependencies.vite` resolves to a
patched line. Check `npm view vitepress dependencies.vite`, not a remembered version.
```

Related failure modes seen in the fleet, all of the same shape — the entry was written well and
stopped being true afterwards:

- an exception whose stated remedy version was later pulled *into* the vulnerable range;
- an exception arguing "there is no fix in the 7.x line" that a backport invalidated days later.

**An expiry date catches an entry that runs out; it does not catch an entry that stops being
true.** While an id sits in the allowlist the gate is silent about it, so *CI is green* and *we
are not exposed* are different statements. **Re-read the advisory from the primary source
(`gh api /advisories/<id>`) whenever you touch the file — not at expiry.**

An expired entry is a task, not a renewal: re-check it, do not extend it by reflex. An entry
that is no longer needed is deleted rather than carried.
