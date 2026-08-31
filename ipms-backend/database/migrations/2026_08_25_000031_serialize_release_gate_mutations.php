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

            CREATE OR REPLACE FUNCTION ipms_require_requirement_project_write_context()
            RETURNS trigger AS $$
            DECLARE
                authorized_context text;
            BEGIN
                IF pg_trigger_depth() > 1 THEN
                    RETURN NULL;
                END IF;

                authorized_context := CASE
                    WHEN TG_OP = 'INSERT' THEN NULLIF(
                        current_setting(
                            'itpms.requirement_project_insert_scope',
                            true
                        ),
                        ''
                    )
                    ELSE NULLIF(
                        current_setting(
                            'itpms.requirement_project_write_ids',
                            true
                        ),
                        ''
                    )
                END;

                IF authorized_context IS NULL THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'IV004',
                        MESSAGE = 'VERSION_SCOPE_BUSY',
                        DETAIL = 'requirement_project writes require a locked workflow scope';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_requirement_project_insert_context
            BEFORE INSERT ON requirement_project
            FOR EACH STATEMENT
            EXECUTE FUNCTION ipms_require_requirement_project_write_context();

            CREATE TRIGGER trg_requirement_project_update_context
            BEFORE UPDATE ON requirement_project
            FOR EACH STATEMENT
            EXECUTE FUNCTION ipms_require_requirement_project_write_context();

            CREATE TRIGGER trg_requirement_project_delete_context
            BEFORE DELETE ON requirement_project
            FOR EACH STATEMENT
            EXECUTE FUNCTION ipms_require_requirement_project_write_context();

            CREATE OR REPLACE FUNCTION ipms_guard_requirement_project_version_mutation()
            RETURNS trigger AS $$
            DECLARE
                acceptance_version_id bigint;
                authorized_ids jsonb;
                insert_scope jsonb;
                release_version_id bigint;
                scope_row record;
                version_id bigint;
                version_status smallint;
            BEGIN
                IF pg_trigger_depth() > 1 THEN
                    IF TG_OP = 'UPDATE'
                       AND OLD.id IS NOT DISTINCT FROM NEW.id
                       AND OLD.requirement_id IS NOT DISTINCT FROM NEW.requirement_id
                       AND OLD.project_id IS NOT DISTINCT FROM NEW.project_id
                       AND OLD.project_version_id IS NOT DISTINCT FROM NEW.project_version_id
                       AND OLD.delivery_status IS NOT DISTINCT FROM NEW.delivery_status
                       AND OLD.version_assigned_by_id IS NOT NULL
                       AND NEW.version_assigned_by_id IS NULL
                       AND OLD.version_assigned_at IS NOT DISTINCT FROM NEW.version_assigned_at
                       AND OLD.created_at IS NOT DISTINCT FROM NEW.created_at THEN
                        RETURN NEW;
                    END IF;

                    IF TG_OP = 'DELETE' THEN
                        IF NOT pg_try_advisory_xact_lock(
                            hashtextextended(
                                'itpms:requirement-project:'
                                    || OLD.requirement_id::text
                                    || ':'
                                    || OLD.project_id::text,
                                0
                            )
                        ) THEN
                            RAISE EXCEPTION USING
                                ERRCODE = 'IV004',
                                MESSAGE = 'VERSION_SCOPE_BUSY',
                                DETAIL = format('requirement_project_id=%s', OLD.id);
                        END IF;

                        IF OLD.project_version_id IS NOT NULL THEN
                            IF NOT pg_try_advisory_xact_lock(
                                hashtextextended(
                                    'itpms:project-version:' || OLD.project_version_id::text,
                                    0
                                )
                            ) THEN
                                RAISE EXCEPTION USING
                                    ERRCODE = 'IV004',
                                    MESSAGE = 'VERSION_SCOPE_BUSY',
                                    DETAIL = format(
                                        'project_version_id=%s',
                                        OLD.project_version_id
                                    );
                            END IF;

                            SELECT status
                            INTO version_status
                            FROM project_versions
                            WHERE id = OLD.project_version_id;

                            IF version_status IN (5, 6, 7) THEN
                                RAISE EXCEPTION USING
                                    ERRCODE = 'IV001',
                                    MESSAGE = 'VERSION_LOCKED',
                                    DETAIL = format(
                                        'project_version_id=%s,status=%s',
                                        OLD.project_version_id,
                                        version_status
                                    );
                            END IF;
                        END IF;

                        RETURN OLD;
                    END IF;
                END IF;

                IF TG_OP = 'INSERT' THEN
                    insert_scope := COALESCE(
                        NULLIF(
                            current_setting(
                                'itpms.requirement_project_insert_scope',
                                true
                            ),
                            ''
                        )::jsonb,
                        '{}'::jsonb
                    );

                    IF (insert_scope->>'requirement_id')::bigint
                            IS DISTINCT FROM NEW.requirement_id
                       OR (insert_scope->>'project_id')::bigint
                            IS DISTINCT FROM NEW.project_id THEN
                        RAISE EXCEPTION USING
                            ERRCODE = 'IV004',
                            MESSAGE = 'VERSION_SCOPE_BUSY',
                            DETAIL = format(
                                'requirement_id=%s,project_id=%s',
                                NEW.requirement_id,
                                NEW.project_id
                            );
                    END IF;
                END IF;

                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    authorized_ids := COALESCE(
                        NULLIF(
                            current_setting('itpms.requirement_project_write_ids', true),
                            ''
                        )::jsonb,
                        '[]'::jsonb
                    );

                    IF NOT authorized_ids @> jsonb_build_array(OLD.id) THEN
                        RAISE EXCEPTION USING
                            ERRCODE = 'IV004',
                            MESSAGE = 'VERSION_SCOPE_BUSY',
                            DETAIL = format('requirement_project_id=%s', OLD.id);
                    END IF;
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

                release_version_id := NULLIF(
                    current_setting('itpms.release_project_version_id', true),
                    ''
                )::bigint;
                IF TG_OP = 'UPDATE'
                   AND OLD.id IS NOT DISTINCT FROM NEW.id
                   AND OLD.requirement_id IS NOT DISTINCT FROM NEW.requirement_id
                   AND OLD.project_id IS NOT DISTINCT FROM NEW.project_id
                   AND OLD.project_version_id IS NOT DISTINCT FROM NEW.project_version_id
                   AND OLD.version_assigned_by_id IS NOT DISTINCT FROM NEW.version_assigned_by_id
                   AND OLD.version_assigned_at IS NOT DISTINCT FROM NEW.version_assigned_at
                   AND OLD.created_at IS NOT DISTINCT FROM NEW.created_at
                   AND NEW.delivery_status = 6
                   AND OLD.project_version_id IS NOT DISTINCT FROM release_version_id
                   AND EXISTS (
                        SELECT 1
                        FROM project_versions
                        WHERE id = OLD.project_version_id
                          AND status IN (4, 5)
                   ) THEN
                    RETURN NEW;
                END IF;

                acceptance_version_id := NULLIF(
                    current_setting('itpms.acceptance_project_version_id', true),
                    ''
                )::bigint;
                IF TG_OP = 'UPDATE'
                   AND OLD.id IS NOT DISTINCT FROM NEW.id
                   AND OLD.requirement_id IS NOT DISTINCT FROM NEW.requirement_id
                   AND OLD.project_id IS NOT DISTINCT FROM NEW.project_id
                   AND OLD.project_version_id IS NOT DISTINCT FROM NEW.project_version_id
                   AND OLD.version_assigned_by_id IS NOT DISTINCT FROM NEW.version_assigned_by_id
                   AND OLD.version_assigned_at IS NOT DISTINCT FROM NEW.version_assigned_at
                   AND OLD.created_at IS NOT DISTINCT FROM NEW.created_at
                   AND OLD.delivery_status = 6
                   AND NEW.delivery_status = 7
                   AND OLD.project_version_id IS NOT DISTINCT FROM acceptance_version_id
                   AND EXISTS (
                        SELECT 1
                        FROM project_versions
                        WHERE id = OLD.project_version_id
                          AND status = 6
                   ) THEN
                    RETURN NEW;
                END IF;

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

            CREATE OR REPLACE FUNCTION ipms_expected_release_gate_result(
                target_version_id bigint,
                requirement_scope jsonb,
                original_status smallint
            )
            RETURNS jsonb AS $$
            DECLARE
                blocking jsonb;
                checks jsonb;
                delivery_failing jsonb;
                delivery_failing_ids jsonb;
                incomplete_count bigint;
                incomplete_task_ids jsonb;
                notes_present boolean;
                open_count bigint;
                open_defect_ids jsonb;
                relevant_count bigint;
                scope_count bigint;
                scope_ids jsonb;
                task_count bigint;
                version_row project_versions%ROWTYPE;
            BEGIN
                SELECT *
                INTO version_row
                FROM project_versions
                WHERE id = target_version_id;

                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;

                SELECT
                    count(*),
                    COALESCE(
                        jsonb_agg(
                            (scope_item->>'requirement_project_id')::bigint
                            ORDER BY ordinal
                        ),
                        '[]'::jsonb
                    )
                INTO scope_count, scope_ids
                FROM jsonb_array_elements(requirement_scope)
                    WITH ORDINALITY AS scope_items(scope_item, ordinal);

                SELECT
                    COALESCE(
                        jsonb_agg(
                            (scope_item->>'requirement_project_id')::bigint
                            ORDER BY ordinal
                        ) FILTER (
                            WHERE (scope_item->>'pre_release_delivery_status')::smallint <> 5
                        ),
                        '[]'::jsonb
                    ),
                    COALESCE(
                        jsonb_agg(
                            jsonb_build_object(
                                'requirement_project_id',
                                (scope_item->>'requirement_project_id')::bigint,
                                'delivery_status',
                                (scope_item->>'pre_release_delivery_status')::smallint
                            )
                            ORDER BY ordinal
                        ) FILTER (
                            WHERE (scope_item->>'pre_release_delivery_status')::smallint <> 5
                        ),
                        '[]'::jsonb
                    )
                INTO delivery_failing_ids, delivery_failing
                FROM jsonb_array_elements(requirement_scope)
                    WITH ORDINALITY AS scope_items(scope_item, ordinal);

                SELECT
                    count(*),
                    count(*) FILTER (WHERE task.status <> 3),
                    COALESCE(
                        jsonb_agg(task.id ORDER BY task.id)
                            FILTER (WHERE task.status <> 3),
                        '[]'::jsonb
                    )
                INTO task_count, incomplete_count, incomplete_task_ids
                FROM tasks AS task
                WHERE task.project_id = version_row.project_id
                  AND task.requirement_id IN (
                      SELECT (scope_item->>'requirement_id')::bigint
                      FROM jsonb_array_elements(requirement_scope) AS scope_item
                  );

                SELECT
                    count(*) FILTER (WHERE defect.severity IN (1, 2)),
                    count(*) FILTER (
                        WHERE defect.severity IN (1, 2)
                          AND defect.status <> 5
                    ),
                    COALESCE(
                        jsonb_agg(defect.id ORDER BY defect.id)
                            FILTER (
                                WHERE defect.severity IN (1, 2)
                                  AND defect.status <> 5
                            ),
                        '[]'::jsonb
                    )
                INTO relevant_count, open_count, open_defect_ids
                FROM defects AS defect
                WHERE defect.project_id = version_row.project_id
                  AND defect.requirement_id IN (
                      SELECT (scope_item->>'requirement_id')::bigint
                      FROM jsonb_array_elements(requirement_scope) AS scope_item
                  );

                notes_present := length(btrim(COALESCE(version_row.release_notes, ''))) > 0;

                checks := jsonb_build_array(
                    jsonb_build_object(
                        'code', 'version_metadata',
                        'label', 'Version owner and planned release date',
                        'passed', true,
                        'blocking', false,
                        'details', jsonb_build_object(
                            'applicable', false,
                            'missing', '[]'::jsonb
                        )
                    ),
                    jsonb_build_object(
                        'code', 'non_empty_scope',
                        'label', 'Version scope is not empty',
                        'passed', true,
                        'blocking', false,
                        'details', jsonb_build_object(
                            'applicable', false,
                            'requirement_project_count', scope_count,
                            'requirement_project_ids', scope_ids
                        )
                    ),
                    jsonb_build_object(
                        'code', 'reviewed_assigned_scope',
                        'label', 'Requirements are approved and have execution owners',
                        'passed', true,
                        'blocking', false,
                        'details', jsonb_build_object(
                            'applicable', false,
                            'unapproved_requirement_project_ids', '[]'::jsonb,
                            'missing_execution_owner_requirement_project_ids', '[]'::jsonb
                        )
                    ),
                    jsonb_build_object(
                        'code', 'project_delivery',
                        'label', 'Project delivery is pending deploy',
                        'passed', jsonb_array_length(delivery_failing_ids) = 0,
                        'blocking', true,
                        'details', jsonb_build_object(
                            'applicable', true,
                            'minimum_status', NULL,
                            'required_status', 5,
                            'failing_requirement_project_ids', delivery_failing_ids,
                            'failing', delivery_failing
                        )
                    ),
                    jsonb_build_object(
                        'code', 'tasks_completed',
                        'label', 'All scope tasks are completed',
                        'passed', incomplete_count = 0,
                        'blocking', true,
                        'details', jsonb_build_object(
                            'applicable', true,
                            'total_count', task_count,
                            'incomplete_count', incomplete_count,
                            'incomplete_task_ids', incomplete_task_ids
                        )
                    ),
                    jsonb_build_object(
                        'code', 'severe_defects_closed',
                        'label', 'Fatal and serious scope defects are closed',
                        'passed', open_count = 0,
                        'blocking', true,
                        'details', jsonb_build_object(
                            'applicable', true,
                            'mode', 'severe',
                            'relevant_count', relevant_count,
                            'open_count', open_count,
                            'open_defect_ids', open_defect_ids
                        )
                    ),
                    jsonb_build_object(
                        'code', 'release_notes_present',
                        'label', 'Release notes are present',
                        'passed', notes_present,
                        'blocking', true,
                        'details', jsonb_build_object(
                            'applicable', true,
                            'present', notes_present
                        )
                    ),
                    jsonb_build_object(
                        'code', 'acceptance_complete',
                        'label', 'All project delivery is accepted',
                        'passed', true,
                        'blocking', false,
                        'details', jsonb_build_object(
                            'applicable', false,
                            'failing_requirement_project_ids', '[]'::jsonb
                        )
                    )
                );

                SELECT COALESCE(
                    jsonb_agg(gate_check ORDER BY ordinal),
                    '[]'::jsonb
                )
                INTO blocking
                FROM jsonb_array_elements(checks)
                    WITH ORDINALITY AS gate_checks(gate_check, ordinal)
                WHERE (gate_check->>'blocking')::boolean
                  AND NOT (gate_check->>'passed')::boolean;

                RETURN jsonb_build_object(
                    'passed', jsonb_array_length(blocking) = 0,
                    'checks', checks,
                    'blocking', blocking,
                    'original_status', original_status
                );
            END;
            $$ LANGUAGE plpgsql STABLE;

            CREATE OR REPLACE FUNCTION ipms_authoritative_release_scope(
                candidate project_version_release_snapshots
            )
            RETURNS jsonb AS $$
                SELECT COALESCE(
                    jsonb_agg(
                        jsonb_build_object(
                            'requirement_project_id', link.id,
                            'requirement_id', link.requirement_id,
                            'project_id', link.project_id,
                            'pre_release_delivery_status', COALESCE(
                                (
                                    SELECT (
                                        failure->>'delivery_status'
                                    )::smallint
                                    FROM jsonb_array_elements(
                                        history.metadata->'failed_gates'
                                    ) AS gate_check
                                    CROSS JOIN LATERAL jsonb_array_elements(
                                        gate_check->'details'->'failing'
                                    ) AS failure
                                    WHERE gate_check->>'code' = 'project_delivery'
                                      AND failure->>'requirement_project_id'
                                          = link.id::text
                                    LIMIT 1
                                ),
                                5
                            ),
                            'delivery_status', 6
                        )
                        ORDER BY link.id
                    ),
                    '[]'::jsonb
                )
                FROM project_versions AS version
                JOIN LATERAL (
                    SELECT release_history.metadata
                    FROM project_version_histories AS release_history
                    WHERE release_history.project_version_id = version.id
                      AND release_history.event_type IN ('release', 'force_release')
                    ORDER BY release_history.id
                    LIMIT 1
                ) AS history ON true
                JOIN requirement_project AS link
                  ON link.project_version_id = version.id
                 AND link.project_id = version.project_id
                WHERE version.id = candidate.project_version_id;
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION ipms_release_snapshot_is_valid(
                candidate project_version_release_snapshots,
                require_context boolean,
                require_final_state boolean
            )
            RETURNS boolean AS $$
                SELECT EXISTS (
                    SELECT 1
                    FROM project_versions AS version
                    WHERE version.id = candidate.project_version_id
                      AND (
                          (
                              require_final_state
                              AND version.status IN (6, 7)
                          )
                          OR
                          (
                              NOT require_final_state
                              AND version.status
                                  = (candidate.gate_result->>'original_status')::smallint
                              AND version.status IN (4, 5)
                          )
                      )
                      AND (
                          NOT require_final_state
                          OR (
                              candidate.released_by_id IS NOT DISTINCT FROM version.released_by_id
                              AND candidate.released_at IS NOT DISTINCT FROM version.released_at
                              AND candidate.release_notes IS NOT DISTINCT FROM version.release_notes
                          )
                      )
                      AND (
                          require_final_state
                          OR candidate.release_notes IS NOT DISTINCT FROM version.release_notes
                      )
                      AND (
                          NOT require_context
                          OR NULLIF(
                              current_setting('itpms.release_snapshot_version_id', true),
                              ''
                          )::bigint = version.id
                      )
                      AND candidate.requirement_scope = CASE
                          WHEN require_final_state
                              THEN ipms_authoritative_release_scope(candidate)
                          ELSE COALESCE(
                              (
                                  SELECT jsonb_agg(
                                      jsonb_build_object(
                                          'requirement_project_id', link.id,
                                          'requirement_id', link.requirement_id,
                                          'project_id', link.project_id,
                                          'pre_release_delivery_status', link.delivery_status,
                                          'delivery_status', 6
                                      )
                                      ORDER BY link.id
                                  )
                                  FROM requirement_project AS link
                                  WHERE link.project_version_id = version.id
                                    AND link.project_id = version.project_id
                              ),
                              '[]'::jsonb
                          )
                      END
                      AND (
                          NOT require_final_state
                          OR NOT EXISTS (
                              SELECT 1
                              FROM requirement_project AS link
                              WHERE link.project_version_id = version.id
                                AND link.project_id = version.project_id
                                AND link.delivery_status NOT IN (6, 7)
                          )
                      )
                      AND candidate.task_count = (
                          SELECT count(*)
                          FROM tasks AS task
                          WHERE task.project_id = version.project_id
                            AND task.requirement_id IN (
                                SELECT (
                                    scope_item->>'requirement_id'
                                )::bigint
                                FROM jsonb_array_elements(
                                    candidate.requirement_scope
                                ) AS scope_item
                            )
                      )
                      AND candidate.defect_count = (
                          SELECT count(*)
                          FROM defects AS defect
                          WHERE defect.project_id = version.project_id
                            AND defect.requirement_id IN (
                                SELECT (
                                    scope_item->>'requirement_id'
                                )::bigint
                                FROM jsonb_array_elements(
                                    candidate.requirement_scope
                                ) AS scope_item
                            )
                      )
                      AND candidate.gate_result = ipms_expected_release_gate_result(
                          version.id,
                          CASE
                              WHEN require_final_state
                                  THEN ipms_authoritative_release_scope(candidate)
                              ELSE candidate.requirement_scope
                          END,
                          (candidate.gate_result->>'original_status')::smallint
                      )
                      AND (
                          NOT require_final_state
                          OR (
                              SELECT count(*)
                              FROM project_version_histories AS release_history
                              WHERE release_history.project_version_id = version.id
                                AND release_history.event_type IN (
                                    'release',
                                    'force_release'
                                )
                          ) = 1
                      )
                      AND (
                          NOT require_final_state
                          OR EXISTS (
                              SELECT 1
                              FROM project_version_histories AS release_history
                              WHERE release_history.project_version_id = version.id
                                AND release_history.event_type = CASE
                                    WHEN candidate.is_override
                                        THEN 'force_release'
                                    ELSE 'release'
                                END
                                AND release_history.from_status
                                    = (candidate.gate_result->>'original_status')::smallint
                                AND release_history.to_status = 6
                                AND release_history.actor_id
                                    IS NOT DISTINCT FROM candidate.released_by_id
                                AND release_history.reason
                                    IS NOT DISTINCT FROM candidate.override_reason
                                AND release_history.created_at
                                    IS NOT DISTINCT FROM candidate.released_at
                                AND release_history.metadata->>'snapshot_id'
                                    = candidate.id::text
                                AND release_history.metadata->>'is_override'
                                    = candidate.is_override::text
                                AND release_history.metadata->'requirement_project_ids'
                                    = COALESCE(
                                        (
                                            SELECT jsonb_agg(link.id ORDER BY link.id)
                                            FROM requirement_project AS link
                                            WHERE link.project_version_id = version.id
                                              AND link.project_id = version.project_id
                                        ),
                                        '[]'::jsonb
                                    )
                                AND release_history.metadata->'failed_gates'
                                    = candidate.gate_result->'blocking'
                                AND release_history.metadata->'snapshot_payload'
                                    = jsonb_build_object(
                                        'requirement_scope',
                                        candidate.requirement_scope,
                                        'task_count',
                                        candidate.task_count,
                                        'defect_count',
                                        candidate.defect_count,
                                        'gate_result',
                                        candidate.gate_result,
                                        'release_notes',
                                        candidate.release_notes
                                    )
                          )
                      )
                      AND (
                          NOT require_final_state
                          OR NOT EXISTS (
                              SELECT 1
                              FROM requirements AS requirement
                              WHERE EXISTS (
                                  SELECT 1
                                  FROM requirement_project AS version_link
                                  WHERE version_link.project_version_id = version.id
                                    AND version_link.requirement_id = requirement.id
                              )
                                AND requirement.status IS DISTINCT FROM (
                                    SELECT min(all_links.delivery_status)
                                    FROM requirement_project AS all_links
                                    WHERE all_links.requirement_id = requirement.id
                                )
                          )
                      )
                      AND (
                          (
                              candidate.is_override
                              AND NULLIF(btrim(candidate.override_reason), '') IS NOT NULL
                          )
                          OR
                          (
                              NOT candidate.is_override
                              AND candidate.override_reason IS NULL
                              AND (candidate.gate_result->>'original_status')::smallint = 5
                              AND (candidate.gate_result->>'passed')::boolean
                          )
                      )
                );
            $$ LANGUAGE sql STABLE;

            CREATE UNIQUE INDEX IF NOT EXISTS
                ux_project_version_histories_single_release
            ON project_version_histories (project_version_id)
            WHERE event_type IN ('release', 'force_release');

            CREATE OR REPLACE FUNCTION ipms_reject_release_history_mutation()
            RETURNS trigger AS $$
            DECLARE
                valid_snapshot boolean;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.event_type NOT IN ('release', 'force_release') THEN
                        RETURN NEW;
                    END IF;

                    IF EXISTS (
                        SELECT 1
                        FROM project_version_histories AS existing_history
                        WHERE existing_history.project_version_id
                            = NEW.project_version_id
                          AND existing_history.event_type IN (
                              'release',
                              'force_release'
                          )
                    ) THEN
                        RAISE EXCEPTION USING
                            ERRCODE = 'IV002',
                            MESSAGE = 'IMMUTABLE_RELEASE_HISTORY';
                    END IF;

                    SELECT EXISTS (
                        SELECT 1
                        FROM project_version_release_snapshots AS snapshot
                        WHERE snapshot.id
                            = (NEW.metadata->>'snapshot_id')::bigint
                          AND snapshot.project_version_id
                            = NEW.project_version_id
                          AND NEW.event_type = CASE
                              WHEN snapshot.is_override
                                  THEN 'force_release'
                              ELSE 'release'
                          END
                          AND NEW.from_status
                            = (snapshot.gate_result->>'original_status')::smallint
                          AND NEW.to_status = 6
                          AND NEW.actor_id
                            IS NOT DISTINCT FROM snapshot.released_by_id
                          AND NEW.reason
                            IS NOT DISTINCT FROM snapshot.override_reason
                          AND NEW.created_at
                            IS NOT DISTINCT FROM snapshot.released_at
                          AND NEW.metadata->>'is_override'
                            = snapshot.is_override::text
                          AND NEW.metadata->'requirement_project_ids'
                            = COALESCE(
                                (
                                    SELECT jsonb_agg(
                                        (
                                            scope_item
                                                ->>'requirement_project_id'
                                        )::bigint
                                        ORDER BY ordinal
                                    )
                                    FROM jsonb_array_elements(
                                        snapshot.requirement_scope
                                    ) WITH ORDINALITY
                                        AS scope_items(scope_item, ordinal)
                                ),
                                '[]'::jsonb
                            )
                          AND NEW.metadata->'failed_gates'
                            = snapshot.gate_result->'blocking'
                          AND NEW.metadata->'snapshot_payload'
                            = jsonb_build_object(
                                'requirement_scope',
                                snapshot.requirement_scope,
                                'task_count',
                                snapshot.task_count,
                                'defect_count',
                                snapshot.defect_count,
                                'gate_result',
                                snapshot.gate_result,
                                'release_notes',
                                snapshot.release_notes
                            )
                    )
                    INTO valid_snapshot;

                    IF NOT valid_snapshot THEN
                        RAISE EXCEPTION USING
                            ERRCODE = 'IV003',
                            MESSAGE = 'INVALID_RELEASE_HISTORY';
                    END IF;

                    RETURN NEW;
                END IF;

                IF OLD.event_type IN ('release', 'force_release')
                   OR (
                       TG_OP = 'UPDATE'
                       AND NEW.event_type IN ('release', 'force_release')
                   ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'IV002',
                        MESSAGE = 'IMMUTABLE_RELEASE_HISTORY';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_project_version_release_history_immutable
                ON project_version_histories;
            CREATE TRIGGER trg_project_version_release_history_immutable
            BEFORE INSERT OR UPDATE OR DELETE ON project_version_histories
            FOR EACH ROW EXECUTE FUNCTION ipms_reject_release_history_mutation();

            DO $$
            DECLARE
                invalid_snapshot_id bigint;
                missing_snapshot_history_id bigint;
                released_without_snapshot_version_id bigint;
            BEGIN
                SELECT snapshot.id
                INTO invalid_snapshot_id
                FROM project_version_release_snapshots AS snapshot
                WHERE NOT ipms_release_snapshot_is_valid(snapshot, false, true)
                ORDER BY snapshot.id
                LIMIT 1;

                IF invalid_snapshot_id IS NOT NULL THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'IV003',
                        MESSAGE = 'INVALID_RELEASE_SNAPSHOT',
                        DETAIL = format(
                            'project_version_release_snapshot_id=%s',
                            invalid_snapshot_id
                        );
                END IF;

                SELECT release_history.id
                INTO missing_snapshot_history_id
                FROM project_version_histories AS release_history
                LEFT JOIN project_version_release_snapshots AS snapshot
                  ON snapshot.project_version_id
                        = release_history.project_version_id
                 AND snapshot.id::text
                        = release_history.metadata->>'snapshot_id'
                WHERE release_history.event_type IN (
                    'release',
                    'force_release'
                )
                  AND snapshot.id IS NULL
                ORDER BY release_history.id
                LIMIT 1;

                IF missing_snapshot_history_id IS NOT NULL THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'IV003',
                        MESSAGE = 'INVALID_RELEASE_SNAPSHOT',
                        DETAIL = format(
                            'project_version_history_id=%s',
                            missing_snapshot_history_id
                        );
                END IF;

                SELECT version.id
                INTO released_without_snapshot_version_id
                FROM project_versions AS version
                WHERE version.status IN (6, 7)
                  AND NOT EXISTS (
                      SELECT 1
                      FROM project_version_histories AS release_history
                      JOIN project_version_release_snapshots AS snapshot
                        ON snapshot.project_version_id
                            = release_history.project_version_id
                       AND snapshot.id::text
                            = release_history.metadata->>'snapshot_id'
                      WHERE release_history.project_version_id = version.id
                        AND release_history.event_type IN (
                            'release',
                            'force_release'
                        )
                  )
                ORDER BY version.id
                LIMIT 1;

                IF released_without_snapshot_version_id IS NOT NULL THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'IV003',
                        MESSAGE = 'INVALID_RELEASE_SNAPSHOT',
                        DETAIL = format(
                            'project_version_id=%s',
                            released_without_snapshot_version_id
                        );
                END IF;
            END;
            $$;

            CREATE OR REPLACE FUNCTION ipms_validate_release_snapshot_insert()
            RETURNS trigger AS $$
            DECLARE
                valid boolean;
            BEGIN
                BEGIN
                    valid := ipms_release_snapshot_is_valid(NEW, true, false);
                EXCEPTION WHEN OTHERS THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'IV003',
                        MESSAGE = 'INVALID_RELEASE_SNAPSHOT',
                        DETAIL = format(
                            'project_version_id=%s',
                            NEW.project_version_id
                        );
                END;

                IF NOT valid THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'IV003',
                        MESSAGE = 'INVALID_RELEASE_SNAPSHOT',
                        DETAIL = format(
                            'project_version_id=%s',
                            NEW.project_version_id
                        );
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_project_version_release_snapshots_validate
            BEFORE INSERT ON project_version_release_snapshots
            FOR EACH ROW EXECUTE FUNCTION ipms_validate_release_snapshot_insert();

            CREATE OR REPLACE FUNCTION ipms_validate_release_snapshot_final()
            RETURNS trigger AS $$
            DECLARE
                valid boolean;
            BEGIN
                BEGIN
                    valid := ipms_release_snapshot_is_valid(NEW, false, true);
                EXCEPTION WHEN OTHERS THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'IV003',
                        MESSAGE = 'INVALID_RELEASE_SNAPSHOT',
                        DETAIL = format(
                            'project_version_id=%s',
                            NEW.project_version_id
                        );
                END;

                IF NOT valid THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'IV003',
                        MESSAGE = 'INVALID_RELEASE_SNAPSHOT',
                        DETAIL = format(
                            'project_version_id=%s',
                            NEW.project_version_id
                        );
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER trg_project_version_release_snapshots_final
            AFTER INSERT ON project_version_release_snapshots
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION ipms_validate_release_snapshot_final();

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

            DROP TRIGGER IF EXISTS trg_project_version_release_snapshots_final
                ON project_version_release_snapshots;
            DROP FUNCTION IF EXISTS ipms_validate_release_snapshot_final();

            DROP TRIGGER IF EXISTS trg_project_version_release_snapshots_validate
                ON project_version_release_snapshots;
            DROP FUNCTION IF EXISTS ipms_validate_release_snapshot_insert();
            DROP FUNCTION IF EXISTS ipms_release_snapshot_is_valid(
                project_version_release_snapshots,
                boolean,
                boolean
            );
            DROP FUNCTION IF EXISTS ipms_authoritative_release_scope(
                project_version_release_snapshots
            );

            -- Release history remains immutable across rollback so a later
            -- preflight never trusts only mutable snapshot history.
            DROP FUNCTION IF EXISTS ipms_expected_release_gate_result(
                bigint,
                jsonb,
                smallint
            );

            DROP TRIGGER IF EXISTS trg_requirement_project_version_gate
                ON requirement_project;
            DROP FUNCTION IF EXISTS ipms_guard_requirement_project_version_mutation();
            DROP TRIGGER IF EXISTS trg_requirement_project_delete_context
                ON requirement_project;
            DROP TRIGGER IF EXISTS trg_requirement_project_update_context
                ON requirement_project;
            DROP TRIGGER IF EXISTS trg_requirement_project_insert_context
                ON requirement_project;
            DROP FUNCTION IF EXISTS ipms_require_requirement_project_write_context();

            DROP TRIGGER IF EXISTS trg_defects_version_gate ON defects;
            DROP TRIGGER IF EXISTS trg_tasks_version_gate ON tasks;
            DROP FUNCTION IF EXISTS ipms_guard_version_work_item_mutation();
            SQL);
    }
};
