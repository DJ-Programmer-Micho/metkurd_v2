# R2A — cancelled by the operator

The separate Docker/native MySQL acceptance environment was cancelled on
2026-09-07. Its prepared Compose and identity-verifier files were removed.
No container, volume, private environment or imported database was created by
Codex. The pre-existing Docker Desktop installation was not uninstalled.

The current task is to upgrade the existing local application database with the
new V2 migrations, after a normal operator-created backup. No new environment,
snapshot import, broad seeder or historical repair belongs to that task.

See [the current local upgrade procedure and evidence](PRODUCTION-DB-IMPORT.md).
