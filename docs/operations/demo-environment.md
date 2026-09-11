# Cloud Demo Environment

This interim demo uses Nginx, PHP 8.2-FPM, PostgreSQL and Redis on Ubuntu 22.04.
The final Phase 4 deployment plan upgrades and hardens this setup separately.
Do not describe these interim operations as production acceptance.

## Layout

- Source: `/srv/itpms-dev/repo`
- Releases: `/var/www/ipms/releases/demo-<UTC timestamp>`
- Shared configuration: `/var/www/ipms/shared/.env` (deployment user, mode 600)
- Shared storage: `/var/www/ipms/shared/storage`
- Candidate: `/var/www/ipms/candidate`, bound only to loopback port 8082
- Public release: `/var/www/ipms/current`
- PHP pool: `/etc/php/8.2/fpm/pool.d/itpms.conf`, runs as `itpms`

The database `itpms_demo` is separate from development and test databases.
All displayed business records come from the database. Initial accounts/projects
are seeded; browser rehearsal adds requirements, project versions and tasks.

## Prepare, Verify, Promote

Use SSH key authentication as the deployment user for development/build/testing.
The one-time Nginx/FPM provisioning and demo promotion scripts currently require a
root operator. Do not put SSH passwords into scripts or command arguments.

On the cloud host:

```bash
cd /srv/itpms-dev/repo/ipms-frontend
npm test
npm run build
cd ../ipms-backend
flock /tmp/itpms-backend-tests.lock php artisan test
```

Root operator, with the desired URL passed explicitly:

```bash
bash /srv/itpms-dev/repo/ops/prepare-demo.sh /srv/itpms-dev/repo "$IPMS_APP_URL"
```

Deployment user:

```bash
node /srv/itpms-dev/repo/ops/qa-demo.cjs
node /srv/itpms-dev/repo/ops/qa-actions.cjs
```

The action rehearsal writes demo records. It is not a read-only smoke check.

After reviewing results, the root operator runs:

```bash
bash /srv/itpms-dev/repo/ops/promote-demo.sh /srv/itpms-dev/repo
```

Promotion preserves the previous Nginx config and restores it if health checks
fail. It never resets the database or rolls back migrations. The complete
backup/restore and schema-compatible release rollback procedure is Phase 4 work.

## Access and Limitations

Accounts: `demo.super_admin`, `demo.it_pm`, `demo.it_member`,
`demo.supplier_pm`, `demo.supplier_dev`, `demo.supplier_tester`, `demo.requester`.
The generated shared demo password is stored only in shared environment
configuration and provided privately to the operator, not in this repository.
A separate `admin` account requires a password change.

Normal version planning/transitions/releases belong to the assigned internal IT
project manager. Superadmin is the separate exceptional force-release authority.

Use no real production data over IP-only HTTP. Outlook transport is not enabled;
no emails are sent by the demo. Full Phase 4 notifications, delivery queues,
reminders and production hardening are not yet complete. Configure a domain and
HTTPS, secure cookies, approved Microsoft Graph credentials, and rotate exposed
high-privilege credentials before production acceptance.
