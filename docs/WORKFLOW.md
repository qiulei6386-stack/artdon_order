# Development Workflow

Use this order for normal changes:

```text
Local changes -> GitHub version -> Server release
```

## Rules

- Make code changes in the local work directory first.
- After every code/content change, finish the cycle by committing to GitHub and then syncing the server before reporting completion.
- Commit and push local changes to GitHub before publishing.
- Publish on the server by pulling from GitHub.
- Do not directly edit production files on the server during normal work.
- If emergency server edits are unavoidable, sync the exact changes back to local and GitHub immediately.
- If a change is intentionally local-only, state that explicitly before stopping.

## Local Work Directory

```text
/Users/qiulei/Library/Mobile Documents/com~apple~CloudDocs/artdon/artdon-singapore
```

## Server Work Directory

```text
/www/wwwroot/artdon_order
```

## Never Commit

The following local-only files must not be uploaded:

```text
SERVER.md
*.pem
*.key
*.command
```
