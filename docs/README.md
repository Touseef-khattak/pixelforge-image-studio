# Docs index

- [implementation.md](implementation.md): phased plan and status. Updated after every phase.
- [pixelforge-api.md](pixelforge-api.md): condensed Pixelforge API reference.
- [money-model.md](money-model.md): how costs, estimates and charges are computed and stored.
- [assessment-brief.md](assessment-brief.md): the original task brief.
- [testing-guide.md](testing-guide.md): step-by-step end-to-end test of the deployed app, with SQL checks and the reconciliation against `/v1/usage`.
- [pixelforge_schema.sql](pixelforge_schema.sql): MySQL/MariaDB schema for manual import (phpMyAdmin) into an empty database. Tables only, no data; includes the `migrations` rows so `php artisan migrate` later does nothing. Generated from the real migrations; regenerate it when a migration is added.
