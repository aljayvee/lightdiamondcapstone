#!/usr/bin/env bash
# =============================================================================
# WBPMS production data cleanup  (draft 2026-11-22, Claude Code — REVIEW FIRST)
# =============================================================================
# Removes all employee / attendance / payroll / request data and keeps what the
# system needs to start fresh:
#
#   KEPT AS-IS   role, branch, job_position, work_schedule, holiday_calendar,
#                request_type, contribution_policy_version, sss_bracket,
#                philhealth_rate, pagibig_rate, payroll_policy_version,
#                attendance_site, biometric_device, biometric_device_branch,
#                phinxlog (migration history), audit_logs (unless CLEAR_AUDIT_LOG=1)
#   KEPT         Business Owner + HR Head accounts, and (KEEP_STAFF_EMPLOYEES=1)
#                the employee profiles those accounts are linked to, with their
#                branch, salary, bank, schedule and biometric enrollment.
#   REMOVED      every other employee and everything hanging off employees:
#                attendance, punches, import batches, policy flags, payroll runs,
#                payslips, periods, requests, leave credits/ledger, cash advances,
#                documents; and the Employee-role login accounts.
#
# Safety:
#   * Default is a DRY RUN: all deletes run inside ONE transaction, the before/
#     after counts and checks are printed, then everything is ROLLED BACK.
#   * --apply: asks you to type the database name, takes a full mysqldump +
#     a copy of the storage files, verifies the dump, then runs the same
#     transaction and COMMITs only if every check passes. Any SQL error or
#     failed check aborts before COMMIT, and MariaDB rolls the whole thing back.
#   * Any table it does not know about stops the script (classify it first).
#   * Files of removed documents / attendance uploads are MOVED into the backup
#     folder, never deleted.
#
# Run on the VPS as root:
#   bash prod_data_cleanup.sh            # dry run (rolled back)
#   bash prod_data_cleanup.sh --apply    # backup, then real cleanup
#
# Options (environment variables):
#   KEEP_STAFF_EMPLOYEES=1   1 = keep Owner/HR employee profiles; 0 = remove them
#                            too (accounts stay, just no longer linked).
#   EMPLOYEE_ACCOUNTS=delete delete     = remove Employee-role logins
#                            deactivate = keep them, set status Inactive
#   CLEAR_AUDIT_LOG=0        1 = also empty audit_logs (the cleanup row is still
#                            written afterwards).
#   APP_DIR=/var/www/wbpms   ENV_FILE=$APP_DIR/.env   BACKUP_ROOT=/root/backups/wbpms
# =============================================================================
set -euo pipefail

APPLY=0
[[ "${1:-}" == "--apply" ]] && APPLY=1
[[ $# -gt 0 && "${1:-}" != "--apply" ]] && { echo "Usage: $0 [--apply]"; exit 2; }

KEEP_STAFF_EMPLOYEES="${KEEP_STAFF_EMPLOYEES:-1}"
EMPLOYEE_ACCOUNTS="${EMPLOYEE_ACCOUNTS:-delete}"
CLEAR_AUDIT_LOG="${CLEAR_AUDIT_LOG:-0}"
APP_DIR="${APP_DIR:-/var/www/wbpms}"
ENV_FILE="${ENV_FILE:-$APP_DIR/.env}"
BACKUP_ROOT="${BACKUP_ROOT:-/root/backups/wbpms}"

[[ "$KEEP_STAFF_EMPLOYEES" =~ ^[01]$ ]] || { echo "KEEP_STAFF_EMPLOYEES must be 0 or 1"; exit 2; }
[[ "$EMPLOYEE_ACCOUNTS" =~ ^(delete|deactivate)$ ]] || { echo "EMPLOYEE_ACCOUNTS must be delete or deactivate"; exit 2; }
[[ "$CLEAR_AUDIT_LOG" =~ ^[01]$ ]] || { echo "CLEAR_AUDIT_LOG must be 0 or 1"; exit 2; }
[[ -f "$ENV_FILE" ]] || { echo "No env file at $ENV_FILE"; exit 2; }

# --- DB settings from .env (password via MYSQL_PWD, never on the command line)
env_get() { grep -E "^$1=" "$ENV_FILE" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//" | tr -d '\r'; }
DB_HOST="$(env_get DB_HOST)"; DB_PORT="$(env_get DB_PORT)"; DB_NAME="$(env_get DB_NAME)"
DB_USER="$(env_get DB_USER)"; export MYSQL_PWD="$(env_get DB_PASSWORD)"
DB_PORT="${DB_PORT:-3306}"
[[ -n "$DB_NAME" && -n "$DB_USER" ]] || { echo "DB_NAME / DB_USER missing in $ENV_FILE"; exit 2; }
MYSQL=(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" --batch --default-character-set=utf8mb4 "$DB_NAME")

# --- Table classification -----------------------------------------------------
KEEP_TABLES=(role branch job_position work_schedule holiday_calendar request_type
  contribution_policy_version sss_bracket philhealth_rate pagibig_rate
  payroll_policy_version attendance_site biometric_device biometric_device_branch phinxlog)
# Wiped completely, in child -> parent order (most FKs are ON DELETE RESTRICT).
WIPE_TABLES=(payroll_adjustment cash_advance_repayment contribution_record payslip
  deduction payroll_earnings payroll payroll_run payroll_period
  leave_ledger cash_advance_history cash_advance_request_detail leave_request_detail
  overtime_request_detail request leave_entitlement
  employee_hr_notice attendance_policy_flag attendance_adjustment attendance
  biometric_punch attendance_import_batch)
# Employee-owned rows: kept only for the staff-linked employees, child -> parent.
PARTIAL_TABLES=(employee_document employee_lifecycle_event employee_employment_episode
  employee_schedule_assignment employee_biometric_enrollment bank_details salary
  employee_branch_assignment)
SPECIAL_TABLES=(employee users sessions audit_logs)

echo "== WBPMS data cleanup  db=$DB_NAME host=$DB_HOST  mode=$([[ $APPLY == 1 ]] && echo APPLY || echo DRY-RUN)"
echo "   KEEP_STAFF_EMPLOYEES=$KEEP_STAFF_EMPLOYEES EMPLOYEE_ACCOUNTS=$EMPLOYEE_ACCOUNTS CLEAR_AUDIT_LOG=$CLEAR_AUDIT_LOG"

ACTUAL=$("${MYSQL[@]}" -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'" | tr -d '\r' | sort)
KNOWN=$(printf '%s\n' "${KEEP_TABLES[@]}" "${WIPE_TABLES[@]}" "${PARTIAL_TABLES[@]}" "${SPECIAL_TABLES[@]}" | sort)
UNKNOWN=$(comm -23 <(echo "$ACTUAL") <(echo "$KNOWN"))
MISSING=$(comm -13 <(echo "$ACTUAL") <(echo "$KNOWN"))
if [[ -n "$UNKNOWN" || -n "$MISSING" ]]; then
  echo "STOP: schema differs from what this script was written for."
  [[ -n "$UNKNOWN" ]] && echo "  Unclassified tables: $(echo $UNKNOWN)"
  [[ -n "$MISSING" ]] && echo "  Expected but missing: $(echo $MISSING)"
  exit 3
fi

# --- Staff accounts / staff-linked employees (computed once, reused in SQL) ----
STAFF_ROLES="'BusinessOwner','HRHead'"
KEEP_SET_SQL="SELECT u.employee_id FROM users u JOIN role r ON r.role_id=u.role_id
               WHERE r.role_name IN ($STAFF_ROLES) AND u.employee_id IS NOT NULL"
[[ "$KEEP_STAFF_EMPLOYEES" == 0 ]] && KEEP_SET_SQL="SELECT NULL AS employee_id FROM DUAL WHERE 1=0"

# Document files that will be removed (relative stored_path values).
DOC_FILES=$("${MYSQL[@]}" -N -e "SELECT stored_path FROM employee_document
  WHERE employee_id NOT IN (SELECT employee_id FROM ($KEEP_SET_SQL) k WHERE employee_id IS NOT NULL)" | tr -d '\r')

# --- APPLY: confirmation + backup ---------------------------------------------
BACKUP_DIR=""
if [[ $APPLY == 1 ]]; then
  read -r -p "Type the database name ($DB_NAME) to confirm the REAL cleanup: " answer
  [[ "$answer" == "$DB_NAME" ]] || { echo "Not confirmed. Nothing changed."; exit 1; }

  BACKUP_DIR="$BACKUP_ROOT/prod_data_cleanup_$(date +%Y%m%d_%H%M%S)"
  mkdir -p "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
  echo "-- Backing up database to $BACKUP_DIR/$DB_NAME.sql.gz"
  mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" --single-transaction --routines \
    --triggers --events --hex-blob --default-character-set=utf8mb4 "$DB_NAME" \
    | gzip -9 > "$BACKUP_DIR/$DB_NAME.sql.gz"
  gzip -t "$BACKUP_DIR/$DB_NAME.sql.gz"
  dumped=$(zcat "$BACKUP_DIR/$DB_NAME.sql.gz" | grep -c '^CREATE TABLE' || true)
  expected=$(echo "$ACTUAL" | wc -l)
  zcat "$BACKUP_DIR/$DB_NAME.sql.gz" | tail -1 | grep -q 'Dump completed' \
    || { echo "STOP: dump did not finish. Nothing changed."; exit 4; }
  [[ "$dumped" -eq "$expected" ]] \
    || { echo "STOP: dump has $dumped tables, database has $expected. Nothing changed."; exit 4; }
  echo "   dump OK ($dumped tables, $(du -h "$BACKUP_DIR/$DB_NAME.sql.gz" | cut -f1))"
  FILE_DIRS=()
  for d in storage/private/employee-documents storage/private/attendance; do
    [[ -d "$APP_DIR/$d" ]] && FILE_DIRS+=("$d")
  done
  if [[ ${#FILE_DIRS[@]} -gt 0 ]]; then
    ( cd "$APP_DIR" && tar czf "$BACKUP_DIR/storage_private.tgz" "${FILE_DIRS[@]}" )
    tar tzf "$BACKUP_DIR/storage_private.tgz" > /dev/null
    echo "   storage files copied to storage_private.tgz (${FILE_DIRS[*]})"
  fi
  echo "   restore: zcat $BACKUP_DIR/$DB_NAME.sql.gz | mysql $DB_NAME"
fi

# --- Build the transaction ------------------------------------------------------
q() { printf '`%s`' "$1"; }
SQL="SET SESSION sql_mode = CONCAT(@@sql_mode, ',STRICT_ALL_TABLES');
SET SESSION innodb_lock_wait_timeout = 30;
CREATE TEMPORARY TABLE keep_emp (employee_id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE keep_emp2 (employee_id BIGINT UNSIGNED PRIMARY KEY);
START TRANSACTION;
INSERT IGNORE INTO keep_emp $KEEP_SET_SQL;
INSERT INTO keep_emp2 SELECT employee_id FROM keep_emp;
SET @staff_users := (SELECT COUNT(*) FROM users u JOIN role r ON r.role_id=u.role_id WHERE r.role_name IN ($STAFF_ROLES));
SET @keep_emp := (SELECT COUNT(*) FROM keep_emp);
"
# Before counts.
for t in "${KEEP_TABLES[@]}" "${WIPE_TABLES[@]}" "${PARTIAL_TABLES[@]}" "${SPECIAL_TABLES[@]}"; do
  SQL+="SET @b_$t := (SELECT COUNT(*) FROM $(q "$t"));"$'\n'
done

SQL+="SELECT CONCAT('staff accounts kept: ', @staff_users, ', staff-linked employee profiles kept: ', @keep_emp) AS plan;
"
# 1) Transactional data, all rows.
for t in "${WIPE_TABLES[@]}"; do SQL+="DELETE FROM $(q "$t");"$'\n'; done
# 2) Employee-owned rows of non-kept employees.
for t in "${PARTIAL_TABLES[@]}"; do
  SQL+="DELETE FROM $(q "$t") WHERE employee_id NOT IN (SELECT employee_id FROM keep_emp);"$'\n'
done
# 3) Employee-role accounts (sessions go with them: ON DELETE CASCADE).
if [[ "$EMPLOYEE_ACCOUNTS" == delete ]]; then
  SQL+="DELETE FROM users WHERE role_id IN (SELECT role_id FROM role WHERE role_name='Employee');"$'\n'
else
  SQL+="UPDATE users SET status='Inactive', updated_at=UTC_TIMESTAMP() WHERE role_id IN (SELECT role_id FROM role WHERE role_name='Employee');"$'\n'
  SQL+="DELETE s FROM sessions s JOIN users u ON u.user_id=s.user_id JOIN role r ON r.role_id=u.role_id WHERE r.role_name='Employee';"$'\n'
fi
# 4) Employees (users.employee_id is ON DELETE SET NULL).
SQL+="DELETE FROM employee WHERE employee_id NOT IN (SELECT employee_id FROM keep_emp);"$'\n'
[[ "$CLEAR_AUDIT_LOG" == 1 ]] && SQL+="DELETE FROM audit_logs;"$'\n'

# 5) Before/after report.
SQL+="SELECT t AS table_name, b AS before_rows, a AS after_rows FROM ("$'\n'
first=1
for t in "${KEEP_TABLES[@]}" "${WIPE_TABLES[@]}" "${PARTIAL_TABLES[@]}" "${SPECIAL_TABLES[@]}"; do
  [[ $first == 1 ]] || SQL+=" UNION ALL "
  SQL+="SELECT '$t' t, @b_$t b, (SELECT COUNT(*) FROM $(q "$t")) a"
  first=0
done
SQL+=") x;"$'\n'

# 6) Checks: any failure SIGNALs an error, the client stops, nothing is committed.
check() { # $1 = condition that must be TRUE, $2 = message
  SQL+="BEGIN NOT ATOMIC IF NOT ($1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CHECK FAILED: $2'; END IF; END//"$'\n'
}
SQL+="DELIMITER //"$'\n'
for t in "${KEEP_TABLES[@]}"; do check "(SELECT COUNT(*) FROM $(q "$t")) = @b_$t" "$t changed"; done
for t in "${WIPE_TABLES[@]}"; do check "(SELECT COUNT(*) FROM $(q "$t")) = 0" "$t not empty"; done
check "(SELECT COUNT(*) FROM users u JOIN role r ON r.role_id=u.role_id WHERE r.role_name IN ($STAFF_ROLES)) = @staff_users" "staff accounts changed"
check "(SELECT COUNT(*) FROM employee) = @keep_emp" "employee count is not the kept staff count"
check "(SELECT COUNT(*) FROM employee e WHERE e.employee_id NOT IN (SELECT employee_id FROM keep_emp2)) = 0" "unexpected employee left"
for t in "${PARTIAL_TABLES[@]}"; do
  check "(SELECT COUNT(*) FROM $(q "$t") WHERE employee_id NOT IN (SELECT employee_id FROM keep_emp)) = 0" "$t has rows of removed employees"
done
if [[ "$EMPLOYEE_ACCOUNTS" == delete ]]; then
  check "(SELECT COUNT(*) FROM users u JOIN role r ON r.role_id=u.role_id WHERE r.role_name='Employee') = 0" "Employee accounts left"
else
  check "(SELECT COUNT(*) FROM users u JOIN role r ON r.role_id=u.role_id WHERE r.role_name='Employee' AND u.status<>'Inactive') = 0" "active Employee accounts left"
fi
check "(SELECT COUNT(*) FROM users u JOIN role r ON r.role_id=u.role_id WHERE r.role_name IN ($STAFF_ROLES) AND u.status='Active') > 0" "no active staff account would remain"
SQL+="DELIMITER ;"$'\n'
SQL+="SELECT 'all checks passed' AS result;"$'\n'

if [[ $APPLY == 1 ]]; then
  SQL+="INSERT INTO audit_logs (user_id, event_type, action_performed, table_affected, record_id, description, action_at, created_at)
VALUES (NULL, 'data_cleanup', 'production_data_cleanup', NULL, NULL,
 CONCAT('Production data cleanup: removed ', @b_employee - (SELECT COUNT(*) FROM employee), ' employees, ',
        @b_attendance, ' attendance rows, ', @b_payroll, ' payroll rows, ', @b_request, ' requests, ',
        @b_users - (SELECT COUNT(*) FROM users), ' accounts. Backup: $BACKUP_DIR'),
 UTC_TIMESTAMP(), UTC_TIMESTAMP());
COMMIT;
SELECT 'COMMITTED' AS result;"
else
  SQL+="ROLLBACK;
SELECT 'DRY RUN - rolled back, nothing changed' AS result;"
fi

# --- Run it ------------------------------------------------------------------
# One client session: if any statement or check fails, the client exits and the
# open transaction is rolled back by the server.
if ! printf '%s\n' "$SQL" | "${MYSQL[@]}" --table; then
  echo "ABORTED: an error or failed check stopped the run before COMMIT. Nothing changed."
  exit 5
fi

# --- APPLY: move files of removed rows into the backup folder ----------------
if [[ $APPLY == 1 ]]; then
  moved=0
  while IFS= read -r rel; do
    [[ -z "$rel" ]] && continue
    case "$rel" in storage/private/*) ;; *) echo "   skip unexpected path: $rel"; continue;; esac
    if [[ -f "$APP_DIR/$rel" ]]; then
      mkdir -p "$BACKUP_DIR/removed_files/$(dirname "$rel")"
      mv "$APP_DIR/$rel" "$BACKUP_DIR/removed_files/$rel"; moved=$((moved+1))
    fi
  done <<< "$DOC_FILES"
  if [[ -d "$APP_DIR/storage/private/attendance" ]]; then
    mkdir -p "$BACKUP_DIR/removed_files/storage/private/attendance"
    find "$APP_DIR/storage/private/attendance" -maxdepth 1 -type f \
      -exec mv -t "$BACKUP_DIR/removed_files/storage/private/attendance" {} + 2>/dev/null || true
  fi
  echo "-- Moved $moved document file(s) and the attendance upload files to $BACKUP_DIR/removed_files"
  echo "== Done. Backup: $BACKUP_DIR"
fi
