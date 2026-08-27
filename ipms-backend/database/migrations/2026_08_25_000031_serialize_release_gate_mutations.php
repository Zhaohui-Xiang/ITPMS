<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ipms_guard_version_work_item_mutation()
            RETURNS trigger AS $$
            DECLARE
                scope_row record;
                version_id bigint;
                version_status smallint;
            BEGIN
                FOR scope_row IN
                    SELECT DISTINCT requirement_id, project_id
                    FROM (
                        SELECT OLD.requirement_id, OLD.project_id
                        WHERE TG_OP <> 'INSERT'
                        UNION ALL
                        SELECT NEW.requirement_id, NEW.project_id
                        WHERE TG_OP <> 'DELETE'
                    ) AS affected_scopes
                    WHERE requirement_id IS NOT NULL
                      AND project_id IS NOT NULL
                    ORDER BY requirement_id, project_id
                LOOP
                    PERFORM pg_advisory_xact_lock(
                        hashtextextended(
                            'itpms:requirement-project:'
                                || scope_row.requirement_id::text
                                || ':'
                                || scope_row.project_id::text,
                            0
                        )
                    );
                END LOOP;

                FOR version_id IN
                    SELECT DISTINCT rp.project_version_id
                    FROM requirement_project AS rp
                    WHERE rp.project_version_id IS NOT NULL
                      AND (
                          (
                              TG_OP <> 'INSERT'
                              AND rp.requirement_id = OLD.requirement_id
                              AND rp.project_id = OLD.project_id
                          )
                          OR
                          (
                              TG_OP <> 'DELETE'
                              AND rp.requirement_id = NEW.requirement_id
                              AND rp.project_id = NEW.project_id
                          )
                      )
                    ORDER BY rp.project_version_id
                LOOP
                    PERFORM pg_advisory_xact_lock(
                        hashtextextended('itpms:project-version:' || version_id::text, 0)
                    );
                END LOOP;

                FOR version_id IN
                    SELECT DISTINCT rp.project_version_id
                    FROM requirement_project AS rp
                    WHERE rp.project_version_id IS NOT NULL
                      AND (
                          (
                              TG_OP <> 'INSERT'
                              AND rp.requirement_id = OLD.requirement_id
                              AND rp.project_id = OLD.project_id
                          )
                          OR
                          (
                              TG_OP <> 'DELETE'
                              AND rp.requirement_id = NEW.requirement_id
                              AND rp.project_id = NEW.project_id
                          )
                      )
                    ORDER BY rp.project_version_id
                LOOP
                    SELECT status
                    INTO version_status
                    FROM project_versions
                    WHERE id = version_id;

                    IF version_status IN (5, 6, 7) THEN
                        RAISE EXCEPTION USING
                            ERRCODE = 'IV001',
                            MESSAGE = 'VERSION_LOCKED',
                            DETAIL = format(
                                'project_version_id=%s,status=%s',
                                version_id,
                                version_status
                            );
                    END IF;
                END LOOP;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_tasks_version_gate
            BEFORE INSERT OR UPDATE OR DELETE ON tasks
            FOR EACH ROW EXECUTE FUNCTION ipms_guard_version_work_item_mutation();

            CREATE TRIGGER trg_defects_version_gate
            BEFORE INSERT OR UPDATE OR DELETE ON defects
            FOR EACH ROW EXECUTE FUNCTION ipms_guard_version_work_item_mutation();

            CREATE OR REPLACE FUNCTION ipms_guard_requirement_project_version_mutation()
            RETURNS trigger AS $$
            DECLARE
                scope_row record;
                version_id bigint;
                version_status smallint;
            BEGIN
                IF TG_OP = 'UPDATE'
                   AND OLD.requirement_id IS NOT DISTINCT FROM NEW.requirement_id
                   AND OLD.project_id IS NOT DISTINCT FROM NEW.project_id
                   AND OLD.project_version_id IS NOT DISTINCT FROM NEW.project_version_id THEN
                    RETURN NEW;
                END IF;

                FOR scope_row IN
                    SELECT DISTINCT requirement_id, project_id
                    FROM (
                        SELECT OLD.requirement_id, OLD.project_id
                        WHERE TG_OP <> 'INSERT'
                        UNION ALL
                        SELECT NEW.requirement_id, NEW.project_id
                        WHERE TG_OP <> 'DELETE'
                    ) AS affected_scopes
                    WHERE requirement_id IS NOT NULL
                      AND project_id IS NOT NULL
                    ORDER BY requirement_id, project_id
                LOOP
                    PERFORM pg_advisory_xact_lock(
                        hashtextextended(
                            'itpms:requirement-project:'
                                || scope_row.requirement_id::text
                                || ':'
                                || scope_row.project_id::text,
                            0
                        )
                    );
                END LOOP;

                FOR version_id IN
                    SELECT DISTINCT candidate
                    FROM (
                        SELECT OLD.project_version_id AS candidate
                        WHERE TG_OP <> 'INSERT'
                        UNION ALL
                        SELECT NEW.project_version_id AS candidate
                        WHERE TG_OP <> 'DELETE'
                    ) AS affected_versions
                    WHERE candidate IS NOT NULL
                    ORDER BY candidate
                LOOP
                    PERFORM pg_advisory_xact_lock(
                        hashtextextended('itpms:project-version:' || version_id::text, 0)
                    );

                    SELECT status
                    INTO version_status
                    FROM project_versions
                    WHERE id = version_id;

                    IF version_status IN (5, 6, 7) THEN
                        RAISE EXCEPTION USING
                            ERRCODE = 'IV001',
                            MESSAGE = 'VERSION_LOCKED',
                            DETAIL = format(
                                'project_version_id=%s,status=%s',
                                version_id,
                                version_status
                            );
                    END IF;
                END LOOP;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_requirement_project_version_gate
            BEFORE INSERT OR UPDATE OR DELETE ON requirement_project
            FOR EACH ROW EXECUTE FUNCTION ipms_guard_requirement_project_version_mutation();

            CREATE OR REPLACE FUNCTION ipms_validate_release_snapshot_insert()
            RETURNS trigger AS $$
            DECLARE
                version_status smallint;
                version_released_by_id bigint;
                version_released_at timestamptz;
                version_release_notes text;
            BEGIN
                SELECT status, released_by_id, released_at, release_notes
                INTO version_status, version_released_by_id, version_released_at, version_release_notes
                FROM project_versions
                WHERE id = NEW.project_version_id;

                IF version_status IS DISTINCT FROM 6
                   OR NEW.released_by_id IS DISTINCT FROM version_released_by_id
                   OR NEW.released_at IS DISTINCT FROM version_released_at
                   OR NEW.release_notes IS DISTINCT FROM version_release_notes
                   OR (NEW.is_override AND NULLIF(btrim(NEW.override_reason), '') IS NULL)
                   OR (NOT NEW.is_override AND NEW.override_reason IS NOT NULL) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'IV003',
                        MESSAGE = 'INVALID_RELEASE_SNAPSHOT',
                        DETAIL = format(
                            'project_version_id=%s,status=%s',
                            NEW.project_version_id,
                            COALESCE(version_status::text, 'missing')
                        );
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_project_version_release_snapshots_validate
            BEFORE INSERT ON project_version_release_snapshots
            FOR EACH ROW EXECUTE FUNCTION ipms_validate_release_snapshot_insert();

            CREATE OR REPLACE FUNCTION ipms_reject_release_snapshot_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION USING
                    ERRCODE = 'IV002',
                    MESSAGE = 'IMMUTABLE_RELEASE_SNAPSHOT';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_project_version_release_snapshots_immutable
            BEFORE UPDATE OR DELETE ON project_version_release_snapshots
            FOR EACH ROW EXECUTE FUNCTION ipms_reject_release_snapshot_mutation();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_project_version_release_snapshots_immutable
                ON project_version_release_snapshots;
            DROP FUNCTION IF EXISTS ipms_reject_release_snapshot_mutation();

            DROP TRIGGER IF EXISTS trg_project_version_release_snapshots_validate
                ON project_version_release_snapshots;
            DROP FUNCTION IF EXISTS ipms_validate_release_snapshot_insert();

            DROP TRIGGER IF EXISTS trg_requirement_project_version_gate
                ON requirement_project;
            DROP FUNCTION IF EXISTS ipms_guard_requirement_project_version_mutation();

            DROP TRIGGER IF EXISTS trg_defects_version_gate ON defects;
            DROP TRIGGER IF EXISTS trg_tasks_version_gate ON tasks;
            DROP FUNCTION IF EXISTS ipms_guard_version_work_item_mutation();
            SQL);
    }
};
