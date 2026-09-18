# RG-001C CI qualification note

Document class: **HISTORICAL_EVIDENCE**  
Purpose: prove pull-request CI check identities for repository governance.

This file exists only to exercise the repository `pull_request` workflow so the
matrix status-check names can be verified before any default-branch protection
ruleset is activated.

Expected PR check identities (from job display names):

```text
PHP 8.1
PHP 8.2
PHP 8.3
PHP 8.4
```

No runtime, package, or production behavior change is intended by this file.
