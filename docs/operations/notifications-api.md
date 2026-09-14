# In-App Notification API

Phase 4 Task 1 provides storage and recipient-scoped read operations. It does not
yet emit business notifications, send Outlook mail, or enable the notification UI.

All endpoints require the existing authenticated Sanctum session:

| Method | Path | Result |
| --- | --- | --- |
| GET | /api/notifications | Standard paginated envelope |
| GET | /api/notifications/unread-count | data.unread_count |
| POST | /api/notifications/{id}/read | Current notification resource |
| POST | /api/notifications/read-all | data.updated_count and data.unread_count |

List parameters are `page` and `page_size` (clamped from 1 to 100). Records sort by
created_at descending, then id descending for stable same-time pagination.

The resource contains id, event_code, title, body, target_type, target_id,
target_url, payload, read_at and created_at. Internal recipient/dedup fields stay
in storage and are not exposed in the API resource.

Every operation starts from the authenticated user's inbox, including superadmins.
A foreign or missing notification returns 404. Read-all never touches other users;
repeated single reads preserve the original timestamp. Unique dedup_key prevents
duplicate storage at database level. Payload uses JSONB; target_url permits only
relative application paths, excluding external/protocol-relative or ambiguous URLs.

Verification on 2026-09-14: notification API tests passed (14 tests); the full
backend suite passed (349 tests). Commands:

```bash
php artisan test --filter InAppNotificationApiTest
flock /tmp/itpms-backend-tests.lock php artisan test
node /srv/itpms-dev/repo/ops/qa-notifications.cjs
```

The browser smoke runs on the cloud host. Set `IPMS_QA_URL` to test the public
release; otherwise it checks the loopback candidate.
The test suite covers list isolation, sorting, counts, one/all read operations,
idempotency, pagination limits, anonymous access, deduplication and link validation.
