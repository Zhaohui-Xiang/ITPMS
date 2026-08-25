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
                version_ids bigint[];
                version_id bigint;
                version_status smallint;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    SELECT array_agg(DISTINCT project_version_id ORDER BY project_version_id)
                    INTO version_ids
                    FROM requirement_project
                    WHERE requirement_id = NEW.requirement_id
                      AND project_id = NEW.project_id
                      AND project_version_id IS NOT NULL;
                ELSIF TG_OP = 'DELETE' THEN
                    SELECT array_agg(DISTINCT project_version_id ORDER BY project_version_id)
                    INTO version_ids
                    FROM requirement_project
                    WHERE requirement_id = OLD.requirement_id
                      AND project_id = OLD.project_id
                      AND project_version_id IS NOT NULL;
                ELSE
                    SELECT array_agg(DISTINCT project_version_id ORDER BY project_version_id)
                    INTO version_ids
                    FROM requirement_project
                    WHERE project_version_id IS NOT NULL
                      AND (
                          (requirement_id = OLD.requirement_id AND project_id = OLD.project_id)
                          OR
                          (requirement_id = NEW.requirement_id AND project_id = NEW.project_id)
                      );
                END IF;

                FOREACH version_id IN ARRAY COALESCE(version_ids, ARRAY[]::bigint[])
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

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_tasks_version_gate
            BEFORE INSERT OR UPDATE OR DELETE ON tasks
            FOR EACH ROW EXECUTE FUNCTION ipms_guard_version_work_item_mutation();

            CREATE TRIGGER trg_defects_version_gate
            BEFORE INSERT OR UPDATE OR DELETE ON defects
            FOR EACH ROW EXECUTE FUNCTION ipms_guard_version_work_item_mutation();

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

            DROP TRIGGER IF EXISTS trg_defects_version_gate ON defects;
            DROP TRIGGER IF EXISTS trg_tasks_version_gate ON tasks;
            DROP FUNCTION IF EXISTS ipms_guard_version_work_item_mutation();
            SQL);
    }
};
