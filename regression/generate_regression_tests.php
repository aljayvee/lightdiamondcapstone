<?php
declare(strict_types=1);

/**
 * Generates the WBPMS full regression test suite as an .xlsx workbook.
 * Uses the project's already-installed PhpSpreadsheet (composer dep).
 *
 * Output: WBPMS_Regression_Test_Suite.xlsx (same folder as this script).
 * Run: php generate_regression_tests.php
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Borders;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/* ----------------------------------------------------------------------
   TEST CASES
   Each row: [TC ID, Module, Feature/Area, Requirement Ref, Priority,
             Test Type, Title, Preconditions, Steps, Expected, Test Data]
   ---------------------------------------------------------------------- */
$cases = [
    // ===== 1. Login & Authentication =====
    ['AUTH-001','Login & Authentication','Login','AUTH','High','Functional','Valid HR Head login','HR Head account exists and active','1. Open /login\n2. Enter HR Head email\n3. Enter valid password\n4. Submit','Authenticated; redirected to HR dashboard; session established','hr@lde.test / <valid>'],
    ['AUTH-002','Login & Authentication','Login','AUTH','High','Functional','Valid Business Owner login','Owner account exists and active','1. Open /login\n2. Enter Owner email\n3. Enter valid password\n4. Submit','Authenticated; redirected to Owner dashboard','owner@lde.test / <valid>'],
    ['AUTH-003','Login & Authentication','Login','AUTH','High','Functional','Valid Employee login','Employee account linked to an employee record','1. Open /login\n2. Enter Employee email\n3. Enter valid password\n4. Submit','Authenticated; redirected to Employee self-service portal','emp@lde.test / <valid>'],
    ['AUTH-004','Login & Authentication','Login','AUTH','High','Negative','Login with wrong password','Valid email exists','1. Open /login\n2. Enter valid email\n3. Enter wrong password\n4. Submit','Login rejected with error; no session created','<valid email> / wrongpass'],
    ['AUTH-005','Login & Authentication','Login','AUTH','Medium','Negative','Login with non-existent email','Email not registered','1. Open /login\n2. Enter unregistered email\n3. Enter any password\n4. Submit','Login rejected with generic error; no user enumeration','nobody@lde.test / x'],
    ['AUTH-006','Login & Authentication','Login','AUTH','Medium','Functional','Email is the only account credential (no username field)','N/A','1. Open /login\n2. Inspect form fields','Only an email field is present (no separate username field)','—'],
    ['AUTH-007','Login & Authentication','RBAC','AUTH','High','Security','Employee cannot access HR payroll','Logged in as Employee','1. As Employee, navigate to /hr/payroll','Access blocked (redirect or 403); no data exposed','—'],
    ['AUTH-008','Login & Authentication','RBAC','AUTH','High','Security','HR cannot perform final payroll approval','Logged in as HR; a payroll run in Submitted status','1. As HR, attempt to Approve the submitted payroll run','Approve action blocked; only Business Owner can finally approve','—'],
    ['AUTH-009','Login & Authentication','Session','AUTH','High','Security','Idle session timeout (30 min)','Logged in, then idle','1. Log in\n2. Wait 30 minutes with no activity\n3. Perform an action','Session expired; user forced to log in again','—'],
    ['AUTH-010','Login & Authentication','Session','AUTH','Medium','Security','Absolute session expiry (12h)','Logged in','1. Log in\n2. Keep session active beyond 12h absolute window\n3. Perform an action','Session invalidated; re-authentication required','—'],
    ['AUTH-011','Login & Authentication','Logout','AUTH','High','Security','Logout destroys session','Logged in','1. Click Logout\n2. Press browser Back','Session destroyed; Back does not restore authenticated state','—'],
    ['AUTH-012','Login & Authentication','CSRF','AUTH','High','Security','CSRF token required on POST','Any state-changing form','1. Submit a POST form with CSRF token removed','Request rejected (403/419); no state change','—'],
    ['AUTH-013','Login & Authentication','Password Reset','AUTH','Medium','Functional','Password reset link emailed','User with email on file','1. Use "Forgot password"\n2. Submit email\n3. Check email','Reset link emailed; link opens reset form','user@lde.test'],
    ['AUTH-014','Login & Authentication','Password Reset','AUTH','Medium','Security','Reset link is single-use and expires','Reset link already consumed once','1. Reuse a consumed reset link','Second use rejected; no password change','—'],
    ['AUTH-015','Login & Authentication','Credentials','AUTH','High','Security','Passwords stored hashed (not plaintext)','N/A','1. Inspect user record password column','Password stored via password_hash(); plaintext not recoverable','—'],
    ['AUTH-016','Login & Authentication','Session Cookie','AUTH','Medium','Security','Secure cookie attributes','Over HTTPS','1. Inspect session cookie attributes','HttpOnly, Secure, SameSite set appropriately','—'],
    ['AUTH-017','Login & Authentication','CSRF','AUTH','Medium','Security','Invalid CSRF token rejected','Logged in','1. Submit form with tampered CSRF token','Request rejected; no state change','—'],
    ['AUTH-018','Login & Authentication','Security & Verification','AUTH','High','Security','Strong password policy, email verification & explicit deactivated/archived account message','Migration 20261121000031 applied; deactivated or archived user account','1. Log in to deactivated/archived user with valid password\n2. Change/reset password with weak password or reusing current password\n3. Verify email verification link / resend flow','1: Explicit deactivated/archived account notice shown (wrong password still generic). 2: Weak/reused password rejected (requires >=8 chars, upper, lower, digit, special). 3: Email verification token marks email_verified_at','Defense P1 #7'],
    ['AUTH-019','Login & Authentication','First-login Email OTP','AUTH','High','Security','First login asks for an emailed 6-digit code after the temporary password is changed','Migration 20261121000033 applied; new Employee account with a real email, still on its temporary password','1. Log in with the temporary password\n2. Set a new password\n3. Open the email "WBPMS — Your verification code"\n4. Enter the code on /verify-otp','Step 2 goes to /verify-otp (not the dashboard) and emails a code to the account email; any other page redirects to /verify-otp until the code is entered; correct code opens the dashboard and marks the email verified (users list no longer shows Unverified)','User request 2026-11-21'],
    ['AUTH-020','Login & Authentication','First-login Email OTP','AUTH','High','Security','Wrong, expired and repeated codes are refused; resend is limited','On /verify-otp with a code sent','1. Enter a wrong code\n2. Enter wrong codes 5 times\n3. Click "Send a new code" twice within a minute\n4. Wait 10+ minutes and enter the old code','1: "Incorrect code" with attempts left. 2: code locked, must send a new one. 3: second click says to wait. 4: "This code has expired". A new code makes older codes stop working','User request 2026-11-21'],
    ['AUTH-021','Login & Authentication','First-login Email OTP','AUTH','Medium','Functional','OTP is asked only once per account','Account that already entered its OTP; second account reset via Forgot Password link','1. Log out and log in again\n2. Owner resets the password (temporary password) and the user logs in and changes it\n3. A never-verified user sets a password through the Forgot Password email link, then logs in','1-2: no OTP step; goes straight to the dashboard. 3: no OTP (the reset link already proved the email)','User request 2026-11-21'],
    ['AUTH-022','Login & Authentication','Reset Password','AUTH','Medium','Negative','Junk reset input and old reset links never cause a server error','Logged out; browser dev tools or curl','1. Open an old emailed link /reset-password?token=abc (or a 4000-character token)\n2. On the code page submit code[]=a, a 4000-digit code, and "Array"\n3. POST /reset-password and /forgot-password/resend without first using Forgot Password','1: Forgot Password page explaining links are no longer used. 2: "Enter the 6-digit code" message. 3: back to Forgot Password. Never 500/502; nothing typed is echoed into the URL','Idiot test on prod 2026-11-21 (was 502)'],
    ['AUTH-023','Login & Authentication','Reset Password','AUTH','High','Functional','Forgot password sends a 6-digit code instead of a link','Active account with a real email','1. Forgot Password, enter the email, Send Code\n2. Open the email\n3. Enter the code, new password and confirm\n4. Log in with the new password','Email holds a 6-digit code (no link), valid 15 minutes. Password changes; Login shows "Your password has been reset". No first-login OTP is asked afterwards. Audit: password_reset_code_sent, password_reset_completed','User request 2026-11-21'],
    ['AUTH-024','Login & Authentication','Reset Password','AUTH','High','Security','Reset code limits and no account enumeration','One real account email and one email with no account','1. Enter a wrong code 5 times\n2. Click "Send a new code" right away, then after 60 s\n3. Request codes until 5 were sent in 24 h, then once more\n4. Repeat Forgot Password with the unknown email','1: "Incorrect code" 4 times, then "Too many incorrect codes"; even the right code is refused. 2: button disabled with countdown; after 60 s a new code arrives and the old one stops working. 3: 6th request sends nothing (audit password_reset_code_limit) but the page looks the same. 4: same page and messages as a real account; no email sent','User request 2026-11-21'],

    // ===== 2. Dashboard =====
    ['DASH-001','Dashboard','HR Dashboard','DASH','High','Functional','HR dashboard summary tiles','At least one employee, request, payroll run, attendance alert','1. Log in as HR\n2. Open dashboard','Tiles show employee count, pending requests, payroll status, attendance alerts','—'],
    ['DASH-002','Dashboard','Owner Dashboard','DASH','High','Functional','Owner dashboard summary','Approved payroll data across branches','1. Log in as Owner\n2. Open dashboard','Shows payroll costs, pending approvals, financial reports across branches','—'],
    ['DASH-003','Dashboard','Employee Dashboard','DASH','High','Functional','Employee dashboard scope','Employee with attendance/requests/payslips','1. Log in as Employee\n2. Open dashboard','Shows own attendance summary, own pending requests, recent payslips','—'],
    ['DASH-004','Dashboard','RBAC','DASH','High','Security','Dashboard numbers respect role scope','Logged in as Employee','1. Compare Employee dashboard counts to HR view','Employee sees only own data; cannot see company-wide counts','—'],
    ['DASH-005','Dashboard','Counts','DASH','Medium','Functional','Counts update after related action','Pending request count visible','1. Note pending requests\n2. Submit a new request\n3. Reload dashboard','Pending request count increments','—'],
    ['DASH-006','Dashboard','Owner Dashboard','DASH','High','Functional','Recently Approved shows the run\'s real totals and an MM/DD/YY period','At least one Approved payroll run with non-zero totals','1. Log in as Business Owner\n2. Open Dashboard → Recently Approved\n3. Open Payroll Approval → Recent Activity','Gross and Net match the run review page (e.g. ₱26,500.00 / ₱26,368.51), never ₱0.00 placeholders; money has thousands separators; period shows as MM/DD/YY – MM/DD/YY','Fixed 2026-11-21 (query had hard-coded 0.00)'],

    // ===== 3. User Management =====
    ['USER-001','User Management','Create','USER','High','Functional','Create user account','Logged in as HR/Owner','1. Open User Management\n2. Add user (email + role)\n3. Save','User saved; new user can log in','newuser@lde.test / HRHead'],
    ['USER-002','User Management','Roles','USER','High','Functional','Edit user role','User exists','1. Change user role\n2. Save\n3. Log in as that user','Permissions change to match new role','—'],
    ['USER-003','User Management','Activate/Deactivate','USER','High','Functional','Deactivate user','Active user','1. Deactivate user\n2. Attempt login as that user','Deactivated user cannot log in','—'],
    ['USER-004','User Management','Archive','USER','Medium','Functional','Archive user account','Active user','1. Archive user\n2. View active users list','Archived user hidden from active list','—'],
    ['USER-005','User Management','Self-protection','USER','High','Negative','Prevent archiving own account','Logged in as the user to archive','1. Attempt to archive own account','Action blocked; cannot archive self','—'],
    ['USER-006','User Management','UI','USER','Medium','UI','Blank-username fallback','A user with blank username display','1. Open User Management list','Blank username shown with fallback; no error/blank cell','—'],
    ['USER-007','User Management','Validation','USER','High','Negative','Duplicate email rejected','Email already used by another user','1. Create user with existing email','Rejected with validation error','existing@lde.test'],
    ['USER-008','User Management','RBAC','USER','High','Security','Only HR/Owner manage users','Logged in as Employee','1. As Employee, attempt /user management','Access blocked','—'],
    ['USER-009','User Management','Reset','USER','Medium','Functional','Admin-initiated password reset','User exists','1. Trigger admin reset\n2. User logs in with new credential','User receives/uses new credential successfully','—'],
    ['USER-010','User Management','UI','USER','Medium','UI','Actions menu fully visible on bottom rows (QA USER TC1)','Several users; scroll to the last rows','1. Open Actions on one of the last rows','Menu opens upward when needed; Edit, Deactivate, Archive and Reset Password all visible','—'],
    ['USER-011','User Management','Lifecycle','USER','High','Functional','Archived employee archives the login (QA USER TC2)','Active employee with a login','1. Archive the employee in Employee Management\n2. Open User Management','Linked user shows Archived (not Inactive) and cannot log in; rehire with reactivation restores it','—'],
    ['USER-012','User Management','RBAC & Self-Protection','USER','High','Security','Only BusinessOwner creates/toggles/archives users; self-deactivation blocked & active sessions revoked','Logged in as HRHead or BusinessOwner','1. As HRHead, open /users and attempt POST /users or /users/{id}/toggle\n2. As BusinessOwner, attempt to deactivate own account\n3. Deactivate another active user','1: Create/toggle/archive hidden and blocked (403) for HRHead. 2: Self-deactivation blocked. 3: Target user deactivated and all active sessions in sessions table revoked immediately','Defense P1 #6'],
    ['USER-013','User Management','RBAC','USER','High','Security','User Management hidden from HR Head (nav + routes)','Logged in as HRHead','1. Check the sidebar
2. Open /users, /users/{id}/edit directly
3. POST /users/{id}/reset-password','1: No User Management link. 2-3: 403 on every /users route. BusinessOwner still sees the link and can use all /users routes','Owner request 2026-11-21'],

    // ===== 4. Employee Management =====
    ['EMP-001','Employee Management','Create','EMP','High','Functional','Add employee record','Branch exists','1. Open Employee Management\n2. Add employee (name, branch, position)\n3. Save','Employee saved; appears under selected branch','—'],
    ['EMP-002','Employee Management','Edit','EMP','High','Functional','Edit employee details','Employee exists','1. Edit name/contact/position\n2. Save','Changes persisted','—'],
    ['EMP-003','Employee Management','Branch Scope','EMP','High','Functional','Per-branch employee scoping','Employees in multiple branches','1. Filter by branch\n2. View list','Only employees of selected branch shown (or all when "All Branches")','—'],
    ['EMP-004','Employee Management','Archive/Rehire','EMP','High','Functional','Archive on separation is reversible','Active employee','1. Archive employee (separation)\n2. Rehire/unarchive','Archive applied; rehire restores employee with history retained','—'],
    ['EMP-005','Employee Management','Archive/Rehire','EMP','High','Functional','Rehire retains history','Previously archived employee','1. Rehire archived employee\n2. View prior records/history','Historical records intact after rehire','—'],
    ['EMP-006','Employee Management','Validation','EMP','Medium','Negative','Employee number uniqueness/format','Employee number rules','1. Create duplicate/invalid employee number','Rejected with validation error','—'],
    ['EMP-007','Employee Management','Bank Details','EMP','High','Functional','Bank account details saved','Employee exists','1. Set account name, number, type\n2. Save\n3. Generate transaction slip','Bank details persisted and used to fill transaction slip','—'],
    ['EMP-008','Employee Management','Bank Details','EMP','Medium','Negative','No bank account on file','Employee with no bank details','1. Generate transaction slip for employee','Slip prints blank account fields; missing-bank notice shown','—'],
    ['EMP-009','Employee Management','Documents','EMP','Medium','Functional','Employee document upload','Employee exists','1. Upload a document\n2. Inspect storage','File stored under storage/private (not public); retrievable','—'],
    ['EMP-010','Employee Management','RBAC','EMP','High','Security','Employee cannot access employee management','Logged in as Employee','1. As Employee, attempt employee management','Access blocked','—'],
    ['EMP-011','Employee Management','Validation','EMP','High','Negative','Biometric device and Enroll ID required on create (QA EMP TC3)','Logged in as HR','1. Add employee without device / Enroll ID\n2. Create Employee','Rejected with field errors; nothing saved','—'],
    ['EMP-012','Employee Management','Validation','EMP','High','Negative','Duplicate Enroll ID rejected (QA EMP TC11)','Enroll ID 1 active on Banga device','1. Add or edit an employee with Enroll ID 1 on the same device','"Already assigned to another active employee" error; not saved','Enroll ID 1'],
    ['EMP-013','Employee Management','Edit','EMP','High','Functional','Editing biometric device / Enroll ID persists (QA EMP TC4)','Employee exists','1. Edit employee, set device and Enroll ID\n2. Save Changes\n3. View Biometric & Login','New device and Enroll ID displayed','—'],
    ['EMP-014','Employee Management','Error handling','EMP','High','Negative','No HTTP 500 on create after a validation error (QA EMP TC2)','Email of an archived employee','1. Create with the archived email (validation shown)\n2. Change email, confirm the email prompt, create','Employee created, or a field error is shown; never "500 Something went wrong"','—'],
    ['EMP-015','Employee Management','Middle name','EMP','Medium','Functional','Full middle name saved and shown in the employee list','Two active employees with the same first and last name; migration 20261121000030 applied','1. Edit each employee and enter a full middle name (e.g. Santiago / Bautista)
2. Save
3. Open Employees list and search by the middle name','List shows "Last, First Middle" with the full middle name (not cut to 5 letters); search by middle name finds the employee; View page label reads "Middle name"','QA feedback (Sir Ryan) 11/21/26'],
    ['EMP-016','Employee Management','Archive & Final Pay','EMP','High','Functional','Archive AWOL/Deceased employee with unapproved payroll run and 1-day tenure','Employee with unapproved payroll run and salary/schedule starting on last working date; migration 20261121000031 applied','1. Open Archive Employee (notice lists unapproved payroll runs)\n2. Select reason AWOL or Deceased with Last Working Date\n3. Recompute payroll run','Archive succeeds without chk_salary_period violation; HRHead cannot archive self/other HRHead; recomputed payroll pays pre-separation worked days and excludes post-separation dates','Defense P0 #3'],
    ['EMP-017','Employee Management','Validation & Wage Floor','EMP','High','Negative','DOLE Region XII minimum daily wage, position default rate, device-branch coverage, age 18–100 & future date guards','Active payroll policy minimum_daily_wage (₱460.00); job_position defaults','1. Select Position on Add/Edit Employee\n2. Submit rate below minimum wage, mismatched device/branch, age <18 or >100, or future effective_from (e.g. 2040)\n3. Select Probationary on HR Head (requires_regular=1)','1: Daily rate auto-populates from position. 2 & 3: All invalid inputs rejected with field-level validation errors','Defense P0 #1, #4, P2 #10'],
    ['EMP-018','Employee Management','Birthdate validation','EMP','High','Negative','Inline under-age message on the Birthdate field','HR on Add Employee','1. Pick a birthdate less than 18 years ago from the date picker (do not leave the field)\n2. Change it to exactly 18 years ago\n3. Submit an under-age birthdate with JavaScript disabled','1: Message "Employee is under age. Employees must be at least 18 years old (born on or before MM/DD/YY)." appears under Birthdate immediately. 2: Message clears. 3: Server shows the same field error with the age, e.g. "under age (17 years old)"','Owner QA 2026-11-21'],
    ['EMP-019','Employee Management','Daily rate limit','EMP','High','Boundary','Daily rate accepts ₱1,000,000.00','HR on Add / Edit / Rehire Employee','1. Enter daily rate 1000000 and save\n2. Enter daily rate 1000000.01 and save','1: Employee saves with daily rate ₱1,000,000.00. 2: Rejected with "Daily rate cannot exceed ₱1,000,000.00."','Owner QA 2026-11-21 (was capped at ₱999,999.99)'],
    ['EMP-020','Employee Management','Create','EMP','High','Functional','Employee number auto-generated in EMP-NNN sequence','Logged in as HR; last added employee is EMP-001','1. Open Add Employee\n2. Type only the first name\n3. Type the last name\n4. Complete the form and Save\n5. Add another employee','2: Employee number box still empty (read-only). 3: Shows EMP-002. 4: Saved as EMP-002; Employee Created page shows it. 5: Gets EMP-003. Older numbers (e.g. 126, EMP-DEMO-001) are not changed and do not affect the sequence','Owner request 2026-11-21'],
    ['EMP-021','Employee Management','UI','EMP','Low','UI','No unmatched Enroll ID list on the employee form','Uploaded workbook has punches for unregistered Enroll IDs','1. Open Add Employee, Edit Employee and Rehire','No "Unmatched Biometric Enroll IDs from Uploaded Workbooks" panel on any of them; Enroll ID is typed. Saving an employee with one of those IDs still re-matches the punches (ATT-026)','Owner request 2026-11-21'],
    ['EMP-022','Employee Management','Create','EMP','High','Negative','Failed Add Employee leaves nothing half-saved','Logged in as HR; login account creation will fail (e.g. email already used by an archived login)','1. Add Employee with that email\n2. Save\n3. Check Employee Management list\n4. Fix the email and save again','2: Error shown on the form. 3: No new employee in the list (no orphan branch/schedule/salary/Enroll ID rows). 4: Saves normally with the same Enroll ID and the next EMP- number','Owner request 2026-11-21'],
    ['EMP-023','Employee Management','Create','EMP','Medium','Functional','Picking a position fills in its default daily rate; a typed rate is never overwritten','Logged in as HR; positions have default rates in Settings (one position without a default)','1. Add Employee: pick a position with a default rate (e.g. HR Head)
2. Change to another position with a default
3. Type a different rate, then change the position again
4. Pick a position with no default rate
5. Rehire an archived employee and change the position','1: Daily rate is filled with the default (still editable) and a note shows; employee type switches to Regular for positions that require it. 2: Rate follows the new default. 3: Typed rate is kept. 4: Auto-filled rate is cleared and must be typed. 5: The previous rate shown on Rehire is kept; an empty rate is filled from the position','Owner request 2026-11-21; autofill restored 2026-11-22'],
    ['EMP-024','Employee Management','Create','EMP','High','Functional','Contract review date for Contractual/Probationary notifies the HR Head','Logged in as HR Head','1. Add Employee, type Contractual
2. Clear the Contract review date and Save
3. Set a date before the hire date and Save
4. Set a date within 14 days and Save; open the bell
5. Edit and Save other details only
6. Edit: change type to Regular and Save
7. Rehire an archived employee as Probationary','1: Contract review date shows, pre-filled hire date + 6 months; hidden for Regular. 2: "Contract review date is required". 3: "must be after the start date". 4: Saved; bell count includes the employee with "Due in N days" and a link to the profile; profile shows the same status. 5: Review date unchanged. 6: Date cleared, employee gone from the bell. 7: Rehire form asks for a review date; saved on the employee','Owner request 2026-11-21'],
    ['EMP-025','Employee Management','Data','EMP','Medium','Functional','Test-data seeder fills missing Government IDs and bank accounts (local/test only)','Local or wbpms_test database; some employees have no SSS/PhilHealth/Pag-IBIG/TIN or no bank account','1. Run phinx seed:run -s EmployeeTestIdsAndBankSeeder\n2. Open an employee profile that had blanks\n3. Open an employee that already had real IDs\n4. Open Edit Employee and Save without changes\n5. Run the seeder again\n6. Run it with -e production','1: Prints how many employees got IDs / bank accounts. 2: Government IDs show 99-… / 9999-… / 999-… values; Bank Account shows BDO, Payroll, 9999… number, effective from hire date. 3: Real IDs unchanged. 4: Saves with no format errors. 5: 0 and 0 (nothing duplicated). 6: Refuses: "must not run in production"','Owner request 2026-11-21'],
    ['EMP-026','Employee Management','Bank Account','EMP','High','Regression','Saving bank details never fails on the effective date (chk_bank_period)','Logged in as HR; employee has an active bank account effective 01/01/20','1. Open the employee\'s Bank Account page\n2. Change the account number, keep the pre-filled effective date, Save\n3. Open the page again, set the effective date to 01/01/20, change the account name, Save\n4. Set the effective date to 12/31/19, Save\n5. Set a later date (e.g. tomorrow) with a new account number, Save','1: Effective date is pre-filled with today (never a date before 01/01/20); hint explains later date = new account, same date = correction. 2: Saved; old account closed the day the new one starts, new one Active. 3: Saved; the current account is corrected in place (no new row, no zero-length period). 4: Field error under Effective date: "must be on or after <current date>"; nothing saved, no SQLSTATE text, no app-error.log entry. 5: Saved; one Active account, previous one Inactive with effective_to = the new date','Prod bug 2026-11-22 (emp 36: HR re-submitted the pre-filled 2020-01-01 date, 3 × chk_bank_period 4025)'],

    // ===== 5. Work Schedule =====
    ['SCH-001','Work Schedule','Config','SCH','High','Functional','Sunday–Thursday 5-day schedule','N/A','1. Create schedule Sun–Thu (5 days)\n2. Save','Schedule saved; Sat as rest day','—'],
    ['SCH-002','Work Schedule','Config','SCH','High','Functional','Friday–Thursday 6-day schedule (Sat rest)','N/A','1. Create schedule Fri–Thu (6 days)\n2. Save','Schedule saved; Saturday as rest day','—'],
    ['SCH-003','Work Schedule','Rest Day','SCH','High','Functional','Rest day reflected in attendance','Schedule set','1. Generate timesheet for a Saturday','Saturday treated as rest day in computation','—'],
    ['SCH-004','Work Schedule','Holidays','SCH','High','Functional','Add holiday to calendar','N/A','1. Add holiday (date + type)\n2. Save','Holiday flagged in payroll/timesheet','—'],
    ['SCH-005','Work Schedule','Holiday Pay','SCH','High','Functional','Holiday pay by holiday type','Holiday + schedule set','1. Compute payroll on a holiday','Holiday pay applies correct rate for type (regular/special)','—'],
    ['SCH-006','Work Schedule','Assignment','SCH','Medium','Functional','Assign schedule to employee/branch','Schedule and employee exist','1. Assign schedule\n2. Import attendance','Schedule applied to attendance/timesheet computation','—'],
    ['SCH-007','Work Schedule','Validation','SCH','Medium','Negative','Overlapping schedule warning','Existing schedule','1. Create overlapping schedule','Warned or prevented','—'],
    ['SCH-008','Work Schedule','Config','SCH','Medium','Functional','Working week configurable per branch','Multiple branches','1. Set different working weeks per branch','Each branch honors its configured working week','—'],
    ['SCH-009','Work Schedule','Assignment','SCH','High','Negative','Assign schedule never shows HTTP 500 (QA SCH TC2)','Employee assigned since date D','1. Assign with effective date before D\n2. Assign with effective date D+1','1: field error shown. 2: saved (one-day previous period allowed)','—'],
    ['SCH-010','Work Schedule','Validation','SCH','Medium','Negative','Duplicate schedule name gives a friendly message (QA SCH TC3)','Schedule "Day Shift (08:00-17:00)" exists','1. New Schedule with the same name\n2. Create','"A work schedule named … already exists" (no SQL text)','—'],
    ['SCH-011','Work Schedule','Break','SCH','Medium','Functional','Break duration computed from break window (QA SCH TC4)','N/A','1. New Schedule, break 12:00–12:45\n2. Save\n3. View table','Duration shows 45 (read-only); table shows 12:00–12:45 (45m)','—'],
    ['SCH-012','Work Schedule','Edit','SCH','High','Functional','Edited shift shown everywhere and attendance recalculated (QA SCH TC5, ATT TC6)','Schedule "Day Shift (07:00-16:30)" assigned','1. Edit time out to 16:00\n2. Save','Name becomes "Day Shift (07:00-16:00)"; late/UT of unlocked attendance recalculated','—'],
    ['SCH-013','Work Schedule','Holidays','SCH','High','Data','2026 holiday calendar matches the official proclamations','Fresh database seeded with HolidayCalendar2026Seeder','1. Run the seeder\n2. Open Settings > Holidays and filter 2026','12 Regular (Jan 1, Mar 20 Eid\'l Fitr, Apr 2, Apr 3, Apr 9, May 1, May 27 Eid\'l Adha, Jun 12, Aug 31, Nov 30, Dec 25, Dec 30); Special Non-Working Feb 17, Apr 4, Aug 21, Nov 1, Nov 2, Dec 8, Dec 24, Dec 31; Special Working Feb 25; no Jan 2 or Oct 31','Proc. 1006 s.2025, 1189 and 1264 s.2026; seeder had Chinese New Year on Feb 5 and two undeclared days'],
    ['SCH-014','Work Schedule','Schedule form','SCH','Medium','Negative','One bad time shows one error, not extra errors on valid fields','HR Head signed in','1. HR → Schedules → New
2. Time in 0800 (no colon), Time out 17:00, Break 60
3. Save
4. Repeat with Time out empty','Only "Time in must use 24-hour HH:MM format." (or only "Time out is required."); no "Time out must be after time in" and no break error on valid fields; no PHP warning in storage/private/logs/app-error.log','Fixed 2026-11-22 (ScheduleController::validateSchedule isset check)'],
    ['SCH-015','Work Schedule','Assign schedule','SCH','High','Negative','Assign Schedule refuses an employee who is not active and gives the real reason for database errors','HR Head signed in; an Inactive/Separated employee id','1. Open Assign Schedule, change the employee value to a non-active or unknown id (dev tools), submit
2. Assign an employee a second time on a date an archived assignment already starts','Step 1: "Choose an active employee from the list." under Employee, nothing saved, no FK error in the log. Step 2: "already has a schedule assignment starting on that date". Other DB failures say "could not be assigned" with a log reference, never "overlaps"','Fixed 2026-11-22 (ScheduleRepository::isActiveEmployee, assignFailureMessage)'],

    // ===== 6. Attendance =====
    ['ATT-001','Attendance','Import','ATT','High','Functional','Upload valid .xls daily log','Approved schedule; employees matched','1. Upload valid BDO/biometric .xls daily log\n2. Parse\n3. Generate timesheet','Punches preserved; per-employee timesheet generated','valid_daily_log.xls'],
    ['ATT-002','Attendance','Import','ATT','High','Negative','Reject non-.xls / wrong format','N/A','1. Upload a .csv/.xlsx or non-OLE file','Rejected (OLE signature check); no import','wrong.csv'],
    ['ATT-003','Attendance','Import','ATT','High','Negative','Duplicate file blocked (idempotency)','File already imported once','1. Re-upload the same file (same checksum)','Blocked as duplicate; no double import','same_file.xls'],
    ['ATT-004','Attendance','Import','ATT','Medium','Negative','Oversized file rejected','N/A','1. Upload a file exceeding size/resource limit','Rejected with limit error','large.xls'],
    ['ATT-005','Attendance','Import','ATT','Medium','Negative','Structural/formula validation','N/A','1. Upload a malformed .xls (bad structure/formulas)','Rejected with parse error','malformed.xls'],
    ['ATT-006','Attendance','Matching','ATT','High','Functional','Employee matching during import','Employees with biometric IDs','1. Upload log\n2. Review matched punches','Punches linked to correct employees by device ID','—'],
    ['ATT-007','Attendance','Timesheet','ATT','High','Functional','Hours/late/undertime/overtime computed','Schedule + punches imported','1. Generate timesheet','Hours, late, undertime, overtime computed per schedule','—'],
    ['ATT-008','Attendance','Adjustment','ATT','High','Functional','Manual attendance adjustment by HR','Imported attendance','1. As HR, adjust a punch/hours\n2. Save','Adjustment overrides computed value; audit logged','—'],
    ['ATT-009','Attendance','RBAC','ATT','High','Security','Only HR Head adjusts attendance','Logged in as Employee','1. As Employee, attempt attendance adjustment','Blocked; only HR Head may adjust','—'],
    ['ATT-010','Attendance','Import Tx','ATT','High','Integration','Import owns DB transaction (idempotent)','N/A','1. Upload file\n2. Interrupt mid-import (simulate)','Rollback/atomic; no partial/orphan records','—'],
    ['ATT-011','Attendance','Security','ATT','Medium','Security','Uploads use private randomized temp paths','N/A','1. Upload a file\n2. Inspect storage/private','Randomized temp path; file removed after parse/import','—'],
    ['ATT-012','Attendance','Preview','ATT','Medium','Functional','Preview parsed punches before commit','Valid file uploaded','1. Upload\n2. Open preview','Parsed punches listed before final commit','—'],
    ['ATT-013','Attendance','Reports','ATT','Medium','Functional','Attendance frequency report','Attendance data exists','1. Open attendance frequency report\n2. Generate','Report generated with frequency breakdown','—'],
    ['ATT-014','Attendance','Boundary','ATT','Medium','Boundary','Exactly on time = not late','Schedule start T','1. Punch in exactly at T','Late = 0; counted on time','—'],
    ['ATT-015','Attendance','Overtime','ATT','High','Functional','Overtime from approved overtime requests','Approved overtime request exists','1. Compute payroll for period','Overtime hours match approved overtime request','—'],
    ['ATT-016','Attendance','Import','ATT','Medium','UI','Recent Imports shows month and year (QA ATT TC1)','At least one import','1. Open Import Workbook','Each batch shows e.g. "Jul 2026"; never "? 0"','—'],
    ['ATT-017','Attendance','Policy Flags','ATT','High','Functional','Saturday rest day and holidays are not AWOL days (QA ATT TC3)','Absent Fri and Sun, Sat rest','1. Run Policy Evaluation\n2. View Evidence','No ConsecutiveAWOL for Fri–Sat–Sun; evidence never lists a rest day','Pugay 07/17–07/19/2026'],
    ['ATT-018','Attendance','Policy Flags','ATT','High','Functional','Tardiness evidence lists exactly the streak (QA ATT TC4)','Late Fri, Sun, Tue with Sat rest and Mon holiday','1. Run Policy Evaluation\n2. View Evidence','Trigger is the third late working day; evidence lists only those three days','—'],
    ['ATT-019','Attendance','Cancel','ATT','High','Functional','Cancelled attendance hidden and excluded from totals (QA ATT TC5)','Imported batch','1. Cancel the batch\n2. Open the timesheet','No gray cards; Hours/Late/UT exclude the cancelled days; days owned by other live imports stay','—'],
    ['ATT-020','Attendance','Calculation','ATT','High','Functional','Lone afternoon punch saved as time-out','Shift 07:00–16:00','1. Import a day with only 16:57','Saved as time-out only (Incomplete), not as a 16:57 time-in','—'],
    ['ATT-021','Attendance','Cancel','ATT','High','Negative','Cancelling an older batch a later upload depends on is blocked','Two uploads of the same month','1. Cancel the older batch','Blocked with "cancel the later upload first"; no approved days turn Cancelled','—'],
    ['ATT-022','Attendance','Calculation','ATT','High','Functional','Double-tap same-side punches flagged Incomplete','Shift 07:00–16:00 (12:00–13:00 break)','1. Import .xls cell with two same-side scans <= 60m apart (e.g. 06:49 06:59 or 16:48 16:49)','Saved as Incomplete (DUPLICATE_TAP) with raw punches preserved and 0 worked/late/undertime; never sneaks into payroll with >1-day penalty','Algan 07/02/2026; Tiad 09/05/2025'],
    ['ATT-023','Attendance','Calculation','ATT','High','Functional','Half-day shifts deduct only actual unpaid break overlap','Shift with break window 12:00–13:00','1. Import or adjust morning half-day (07:06–11:59) or afternoon half-day (13:07–17:00)','Worked/late/undertime exclude the 60m unpaid break outside worked hours (worked+late+undertime = standard_minutes)','Catayoc B. 06/19/2026; Defiño 12/18/2025'],
    ['ATT-024','Attendance','Coverage','ATT','High','Functional','Partial-month .xls batch bounds upload coverage to actual punch dates','Approved partial-month import ending 08/28 Fri (aug.xls)','1. Open timesheet matrix / frequency report or compute payroll for 08/28–09/03','Uncovered dates after 08/28 (e.g. 08/30 Sun, 08/31 Mon) are not marked Covered or recorded as unpaid absences; absences inside 08/02–08/28 still count','Daily Log - H(20260830_1439)aug.xls'],
    ['ATT-025','Attendance','Policy Flags','ATT','High','Functional','Policy flag evaluation and late evidence exclude Cancelled import batches','Attendance rows linked to a Cancelled attendance_import_batch','1. Run AttendancePolicyService::evaluateEmployee()\n2. Inspect listFlags()/findFlag() late evidence','Cancelled-batch rows never trigger ConsecutiveLate/AWOL flags or appear in late_dates evidence','—'],
    ['ATT-026','Attendance','Biometric Re-Match & Preview','ATT','High','Functional','Import preview match diagnostics, unmatched punch re-match reconciliation & payroll period auto-creation','Workbook with unregistered Enroll ID (e.g. Enroll ID 21 Richie Tagaduar)','1. Upload workbook and inspect dry-run Enrollment Match Diagnostics\n2. Enroll employee with Enroll ID 21 (auto-triggers re-match or click Re-Match Unmatched Punches)\n3. Approve import for a month without payroll periods','1: Preview flags Unregistered Enroll ID / hire date / branch mismatch before commit. 2: Unmatched punches re-match and generate Complete attendance rows. 3: Friday–Thursday payroll periods auto-created','Defense P0 #1, P2 #10.6'],
    ['ATT-027','Attendance','Manual Override','ATT','High','Functional','Audited manual attendance entry for ABSENT days with mandatory evidence','Timesheet with an ABSENT cell in an unlocked payroll period','1. As HR Head, click the ABSENT cell to open /hr/attendance/manual\n2. Enter 24h Time In, Time Out, and mandatory evidence/reason\n3. Save and open Adjust view','Attendance row created (source=manual, status=Complete); attendance_adjustment (manual_absence_override) and audit_logs recorded and visible in Adjustment History','Defense P0 #2, #5.4'],
    ['ATT-028','Attendance','Biometric Re-Match & Preview','ATT','High','Regression','Re-match Unmatched Punches button links waiting punches and writes its audit row','Approved import batch with unmatched punches for an Enroll ID; employee with that Enroll ID added afterwards (same device, branch covered by the device, schedule assigned)','1. As HR Head, open /hr/attendance\n2. Click Re-match Unmatched Punches\n3. Check the employee\'s attendance and the Audit Trail','Success flash "Re-matched N previously unmatched punch(es)..."; no "Unknown column \'action_type\'" error; punches become matched and attendance days are created; audit_logs row event_type=attendance_rematched, table_affected=biometric_punch','Prod bug 11/21/26: INSERT used non-existent audit_logs columns and rolled back the whole re-match; guard AuditLogInsertColumnsTest'],
    ['ATT-029','Attendance','Repeated Scans & Lunch Punches','ATT','High','Regression','A second scan at arrival or departure, or a lunch out/in pair, gives a Complete (payable) day; only odd patterns need review (owner logic review C7)','Schedule 07:00–16:00, break 12:00–13:00; biometric file with these days for one employee','1. Upload a day with 06:44 07:13 16:30
2. Upload a day with 07:14 16:31 16:42
3. Upload a day with 07:00 12:02 12:58 16:00
4. Upload a day with 07:08 08:54 17:07
5. Upload a day with 06:49 06:59 only','1–3: status Complete; time-in = first punch, time-out = last punch; same minutes as a two-punch day. 4: ReviewRequired. 5: Incomplete (time-out missing). On the Jun–Aug 2026 and Sep–Dec 2025 exports, review days drop from 89 to 9','Owner logic review C7'],

    // ===== 7. Request Management =====
    ['REQ-001','Request Management','Leave','REQ','High','Functional','Submit leave request','Employee logged in with leave balance','1. New leave request\n2. Submit','Status = Pending','—'],
    ['REQ-002','Request Management','Overtime','REQ','High','Functional','Submit overtime request','Employee logged in','1. New overtime request\n2. Submit','Status = Pending','—'],
    ['REQ-003','Request Management','Cash Advance','REQ','High','Functional','Submit cash advance request','Employee logged in','1. New cash advance request\n2. Submit','Status = Pending','—'],
    ['REQ-004','Request Management','Leave Balance','REQ','High','Negative','Block leave when sick balance insufficient','Employee has 0 sick leave balance','1. Submit sick leave request','Request blocked; insufficient balance (4 paid sick leaves)','—'],
    ['REQ-005','Request Management','Approval','REQ','High','Functional','HR Head approves request','Pending request exists','1. As HR, approve request','Status = Approved','—'],
    ['REQ-006','Request Management','Approval','REQ','High','Functional','Business Owner approves/cancels request','Pending request exists','1. As Owner, approve or cancel request','Status updated (Approved/Cancelled)','—'],
    ['REQ-007','Request Management','Status','REQ','High','Functional','Declined request = Cancelled (no Rejected)','Pending request to be declined','1. Decline a request','Status = Cancelled (no separate Rejected status)','—'],
    ['REQ-008','Request Management','Cancel','REQ','Medium','Functional','Employee cancels own pending request','Own pending request','1. Cancel own pending request','Status = Cancelled','—'],
    ['REQ-009','Request Management','Update','REQ','Medium','Functional','Employee updates own pending request','Own pending request','1. Edit own pending request\n2. Save','Updated; still Pending','—'],
    ['REQ-010','Request Management','Integration','REQ','High','Integration','Approved request affects payroll','Approved leave/overtime/cash advance','1. Compute payroll for the period','Payroll reflects approved leave/overtime/cash advance','—'],
    ['REQ-011','Request Management','Cash Advance','REQ','High','Functional','Cash advance deducted in next payroll','Approved cash advance','1. Run payroll after approval','Cash advance appears as deduction; net reduced','—'],
    ['REQ-012','Request Management','RBAC','REQ','High','Security','Employee cannot approve others requests','Logged in as Employee','1. Attempt to approve another request','Blocked','—'],
    ['REQ-013','Request Management','Validation','REQ','Medium','Negative','Invalid request type rejected','N/A','1. Submit request with invalid type','Rejected with validation error','—'],
    ['REQ-014','Request Management','Filters','REQ','Medium','Functional','Owner request filters','Owner, multiple requests','1. Use owner request filters','Requests filtered (by type/status/branch/employee)','—'],
    ['REQ-015','Request Management','Leave Balance','REQ','High','Functional','Leave balance decrements after approval','Employee with sick leave balance','1. Approve sick leave\n2. Check balance','Balance decremented by approved days','—'],
    ['REQ-016','Request Management','HR Self-Request','REQ','High','Security','HR Head own request skips self-verification and routes directly to Business Owner','Employee linked to HRHead user account','1. Submit leave/overtime/cash advance request for the HR Head employee\n2. Attempt HR verification on own request','Request created directly in PendingOwnerApproval (or self-verification blocked); only BusinessOwner can approve','Defense P2 #11.4'],
    ['REQ-017','Request Management','Cash Advance Tracker','REQ','Medium','Functional','Owner\'s Cash Advance Balances back link stays in the Owner area','Logged in as Business Owner','1. Request Approvals → Cash Advance Balances\n2. Click "← Back to Requests"','Returns to /owner/requests (Owner Request Approvals), not the HR-only /hr/requests (Access Denied)','Fixed 2026-11-21'],
    ['REQ-018','Request Management','Leave Credits','REQ','High','Functional','Leave Credit list shows 4.0 remaining for employees whose yearly entitlement is not provisioned yet','Active employee never opened in Leave Credits and with no leave request this year','1. HR → Requests → Leave Credits\n2. Note the employee\'s Remaining / Used\n3. Click View/Adjust, then go back','Remaining 4.0 and Used 0.0 both before and after opening the employee; Depleted count excludes them; Total Days Remaining = 4 × employees without leave used','Fixed 2026-11-21 (list showed 0 / used 4 until the row was opened)'],
    ['REQ-019','Request Management','Leave Days','REQ','High','Functional','Sick leave charges only scheduled working days (rest days and holidays are free)','Employee on a Sun–Fri schedule with 4.0 credits; no holiday in the range','1. As the employee file Sick Leave Sat → Mon\n2. HR approve, Owner approve\n3. Open Leave Credits and the request','Days requested = 2.00 (Sun + Mon), credit 4.0 → 2.0; if Sunday already has attendance only Mon is charged (1.00); a Saturday-only request is refused ("no scheduled working days left to charge"); payroll pays the same days that were charged','Fixed 2026-11-22 (was calendar days: Sat–Mon took 3, paid 1); LeaveDayCounterTest'],
    ['REQ-020','Request Management','Leave Balance','REQ','High','Negative','Leave credit cannot be overspent by several requests waiting for approval','Employee with 4.0 credits','1. File leave for 3 working days (leave Pending)\n2. File another leave for 3 working days\n3. Try to Owner-approve a request needing more days than the credit left','Step 2 refused: "3.00 day(s) are already in requests waiting for approval"; Owner approval re-checks the credit (row locked) and refuses with "Not enough sick-leave credit"; balance never goes below 0','Fixed 2026-11-22 (two 3-day leaves both approved → balance −2)'],
    ['REQ-021','Request Management','Payroll Lock','REQ','High','Negative','Leave or overtime for a day already in an Approved payroll run is refused','Employee with a row in an Approved payroll run covering the date','1. File leave or OT for a date in that week\n2. (Old request) HR approve or Owner approve one dated in that week','Refused with "... can no longer be paid: that day is already approved (payroll run #N). Use a Manual Payroll Adjustment in the next payroll run instead."; dates in the same week but after a branch transfer (outside that payroll row) are still accepted','Added 2026-11-22'],

    // ===== 8. Payroll =====
    ['PAY-001','Payroll','Compute','PAY','High','Functional','Payroll derived from inputs','Approved attendance + salary + requests + contributions','1. Compute payroll for period/branch','Figures derived (not hand-entered) from attendance+salary+requests+contributions','—'],
    ['PAY-002','Payroll','Compute','PAY','High','Negative','Payroll figures not manually editable','Computed payroll','1. Attempt to manually edit a computed figure','Editing not allowed; figures are computed only','—'],
    ['PAY-003','Payroll','Workflow','PAY','High','Functional','HR submits payroll to Owner','Computed payroll ready','1. As HR, submit payroll run','Status = Submitted; routed to Owner','—'],
    ['PAY-004','Payroll','Workflow','PAY','High','Functional','Owner reviews and approves/returns','Submitted payroll','1. As Owner, review\n2. Approve or Return','Owner can approve or return for revision','—'],
    ['PAY-005','Payroll','Workflow','PAY','High','Functional','Owner returns payroll for revision','Submitted payroll','1. As Owner, Return\n2. As HR, recompute','Status = Returned; HR can recompute and resubmit','—'],
    ['PAY-006','Payroll','Workflow','PAY','High','Functional','Owner approval locks run','Returned/resubmitted payroll','1. As Owner, Approve','Status = Approved; run locked','—'],
    ['PAY-007','Payroll','Cancel','REQ047','High','Functional','Cancel payroll run','Approved/submitted run','1. Cancel a payroll run','Cancellation handled; schema/cancellation per REQ047','—'],
    ['PAY-008','Payroll','Immutability','REQ047','High','Security','Approved run immutable','Approved payroll run','1. Attempt to edit approved run','Edit blocked; immutability enforced','—'],
    ['PAY-009','Payroll','Payslip','PAY','High','Functional','Payslip generation','Approved payroll run','1. Generate payslips','Per-employee payslip with earnings, deductions, net','—'],
    ['PAY-010','Payroll','13th Month','PAY','High','Functional','13th-month pay computation','Eligible employee, full-year data','1. Compute 13th-month pay','13th-month computed per rule','—'],
    ['PAY-011','Payroll','Overtime','PAY','High','Functional','Overtime pay from approved overtime','Approved overtime + holiday','1. Compute payroll','Overtime pay per ApprovedOvertimeCalculator/HolidayPayCalculator','—'],
    ['PAY-012','Payroll','Holiday','PAY','High','Functional','Holiday pay on holidays','Payroll period with a holiday','1. Compute payroll','Holiday pay applied for holiday dates','—'],
    ['PAY-013','Payroll','Adjustment','PAY','Medium','Functional','Payroll adjustment service','Computed payroll','1. Apply a payroll adjustment','Adjustment applied and recorded','—'],
    ['PAY-014','Payroll','Multi-branch','PAY','Medium','Functional','Multi-branch run per period','Period with multiple branches','1. Run payroll across branches','Each branch run computed for the period','—'],
    ['PAY-015','Payroll','Disbursement','REQ076','Medium','Functional','Disbursement summary / deposit-slip list export','All branch runs Approved','1. Open disbursement summary\n2. Export CSV','CSV export generated (REQ076)','—'],
    ['PAY-016','Payroll','Net Pay','PAY','High','Functional','Net = gross - deductions','Contributions + other deductions set','1. Compute payroll','Net pay = gross − (SSS + PhilHealth + Pag-IBIG + other deductions)','—'],
    ['PAY-017','Payroll','Holiday','REQ047','High','Functional','Holiday pay honors rest-day multiplier and unworked Regular Holidays','Active holiday in period; Sun–Fri schedule (Sat rest)','1. Compute payroll covering an unworked Regular Holiday (e.g. Dec 25) and a worked rest-day holiday (e.g. Nov 1 Sat)','Covered employee receives 1.00× daily rate for unworked Regular Holiday; worked rest-day SpecialNonWorking holiday pays 1.50× daily rate','dec 2025_.xls; nov 2025_.xls'],
    ['PAY-018','Payroll','History','PAY','High','Functional','Historical payroll resolves Superseded salary, Archived end-dated schedule, and per-day standard minutes','Employee whose salary or schedule changed in a later period','1. Compute payroll for an earlier period (e.g. Sep–Dec 2025)','Uses the salary and schedule effective on period_start and each attendance row standard_minutes for undertime/OT','sept–dec 2025_.xls'],
    ['PAY-019','Payroll','Holiday','REQ047','High','Functional','Worked SpecialNonWorking holiday persists earning_type without SQL truncation and exact holiday OT','Worked SpecialNonWorking holiday (e.g. Nov 1 Sat or Dec 8) with approved OT; migration 030 applied','1. Compute payroll covering worked SpecialNonWorking holiday + regular-day OT','payroll_earnings stores SpecialNonWorkingHoliday cleanly; holiday OT swap matches per-day regular OT with zero centavo drift','nov 2025_.xls; dec 2025_.xls; aug.xls'],
    ['PAY-020','Payroll','Holiday','REQ047','High','Functional','DOLE unworked Regular Holiday eligibility, Incomplete exclusion, and overlapping history deduplication','Regular Holiday in period (e.g. Dec 25 after Dec 24 SNW, or Jun 12 with Incomplete punch); overlapping/archived historical salary/schedule','1. Compute payroll for absent-before-holiday, eligible-before-holiday, Incomplete-on-holiday, and rehired/overlapping history employees','Absent on preceding workday gets ₱0; present/paid-leave on preceding workday (skipping rest/SNW days) gets 1.00×; Incomplete day is skipped; exactly 1 payroll row per employee','dec 2025_.xls; june.xls; aug.xls'],
    ['PAY-021','Payroll','Earnings Integrity','REQ047','High','Functional','SUM(payroll_earnings.amount) strictly equals payroll.gross_pay on zero-worked Special / SpecialNonWorking holidays','Approved attendance row with 0 worked minutes on a Special or SpecialNonWorking holiday','1. Compute payroll run\n2. Compare SUM(payroll_earnings.amount) against payroll.gross_pay and inspect Basic Pay quantity','Unpaid zero-worked special holiday excluded from Basic Pay quantity/amount; SUM(payroll_earnings.amount) === payroll.gross_pay to the centavo','Nov 1 / Dec 24 SNW holiday'],
    ['PAY-022','Payroll','Withholding Tax & Cash Advance','PAY','High','Functional','BIR TRAIN weekly withholding tax, zero-gross deduction skip, and Cash Advance repayment settled only on Owner approval','Employee 1 with ₱0 gross + active CA; Employee 2 with taxable income above weekly ₱4,807.69 threshold + active CA','1. Compute payroll run twice\n2. Inspect deductions and cash_advance_history.remaining_balance\n3. Submit and Approve payroll run','1: Emp 1 has ₱0 deductions (no SSS/PhilHealth/Pag-IBIG/CA). 2: Emp 2 has BIR TRAIN IncomeTax deduction; CA balance unchanged during compute/recompute. 3: Upon Owner approval, CA balance decrements once and records cash_advance_repayment','Defense P0 #5.2, P1 #8'],
    ['PAY-023','Payroll','Pre-Compute Check','PAY','Critical','Functional','Compute lists days that would be left unpaid and will not run until HR resolves them or confirms (owner logic review C6)','Draft run; one employee has an Incomplete day, a ReviewRequired day, a day in an unapproved upload, and a working day after the end of the uploaded file','1. Open the payroll run
2. Press Compute without ticking the confirmation
3. Tick the confirmation and Compute
4. Open the Audit Trail','1: "Check before computing" lists the 4 days with reasons and an Open attendance link; rest days, holidays, approved leave and known absences are not listed. 2: Nothing is computed; error says how many days would be unpaid. 3: Run computed; message states the days left unpaid. 4: compute_with_unresolved_days entry with the count','Owner logic review C6'],
    ['PAY-024','Payroll','Manual Payroll Adjustment','PAY','Critical','Regression','Manual deduction and manual additional pay save and update the totals','Computed or Returned payroll run with at least one employee','1. Open the run and click Adjust on an employee
2. Choose Manual deduction, amount 100, reason "sample", Save
3. Adjust again: Manual additional pay, amount 100, Save','No SQLSTATE[HY093] Invalid parameter number and no "Data truncated for column deduction_type"; deduction / earning line (ManualAdjustment, unit rate = amount) added; employee and run gross, deductions and net updated; payroll_adjustment and audit_logs (payroll_adjusted) rows written','Prod bug 11/21/26: INSERT reused :amount (HY093) and migration 20261018000024 dropped ManualAdjustment from deduction_type (fixed by 20261121000032); guards SqlPlaceholderReuseTest, PayrollLineTypeEnumMigrationTest'],
    ['PAY-025','Payroll','Other Branches Note','PAY','High','Functional','Payroll run page warns about other branches with attendance in the same period and links to create their run','One biometric device covers two branches; attendance uploaded for both; a payroll run exists for one branch only (e.g. Banga Construction Supplies, Jul 24-30, 2026)','1. Open the run for the first branch\n2. Read the yellow note under the page title\n3. Click Create run for this period\n4. Create and compute the second branch run, then reopen the first run','Note: "Other branches are not in this run" with the other branch, its employee and attendance-day counts (cancelled uploads excluded); Create Run form opens with that month, period and branch selected; after the second run exists the note is replaced by a link to it with its status; a cancelled run shows the note without the Create link','Classmate question 11/21/26: "24 employees missing" were Banga Auto Supplies staff with no run for the week; PayrollOtherBranchNoteTest'],
    ['PAY-026','Payroll','Earning Details','PAY','High','Functional','Basic Pay line shows days × rate and the attendance hours behind it','Computed run with attendance (e.g. Jul 24-30, 2026); one employee with approved paid leave','1. Open the run (HR) or Review Payroll (Owner)\n2. Expand View items under Earning Details\n3. Change an attendance day for one employee, then reopen the run without computing','Basic Pay shows "N days × ₱rate" and "Attendance: N days · Xh YYm worked" (+ late / undertime when > 0) from Complete/Approved attendance in the period and branch window, cancelled uploads excluded; leave lines show days × rate only; after the attendance change the line says the days differ and to compute again','Owner request 11/21/26: Basic Pay had to be matched to attendance by hand; prod check: 67/67 lines with unchanged attendance match, 11 differ only where attendance changed after Compute; PayrollBasicPayAttendanceTest'],
    ['PAY-027','Payroll','Date Display','PAY','Medium','UI','HR payroll, salary and benefits pages show MM/DD/YY dates and run times in Manila time','A payroll run created/approved between 00:00 and 08:00 Manila (e.g. run 2, reviewed 2026-11-20 17:34 UTC)','1. As HR open Payroll Processing, a run page, Salary Management, Benefits\n2. As Employee open My Payslips','No YYYY-MM-DD dates anywhere; periods read MM/DD/YY – MM/DD/YY; run Created/Submitted/Reviewed read MM/DD/YY HH:MM in Manila time (run 2 Reviewed = 11/21/26 01:34, not 11/20)','Fixed 2026-11-21; FormatterUtcDateTimeTest'],
    ['PAY-028','Payroll','Workflow','PAY','High','Negative','A payroll week cannot be submitted or approved before it ends','Computed run for the current Fri–Thu week (e.g. 11/20–11/26/26, today 11/22)','1. As HR click Submit for approval\n2. (Run already pending) As Owner click Approve','Both refused: "This payroll week ends on 11/26/26. It can be submitted for approval / approved from 11/27/26 ..."; Compute still works as a preview; on payday Friday both work','Fixed 2026-11-22 (prod run 26 was approved on 11/21); PayrollPeriodClosureTest'],
    ['PAY-029','Payroll','Requests to Payslip','PAY','High','Functional','Approved leave, overtime and cash advance appear correctly on the payslip','Employee ₱500/day, 480-min schedule, absent one workday, worked to 19:00 one day; ₱1,500 cash advance','1. File and fully approve Sick Leave for the absent day, OT 17:00–19:00, cash advance ₱1,500\n2. Compute, submit, approve the run\n3. Open the payslip','Earnings: Approved Sick Leave ₱500.00 (request linked), Approved overtime pay 120 min ₱156.25 (500/480 × 1.25); deduction Cash advance repayment ₱500.00; CA balance 1,500 → 1,000 only after Owner approval; payslip totals = run figures','Verified 2026-11-22 on a copy of prod data (emp 6, run 1: gross 3,156.25, net 2,656.25)'],

    // ===== 9. Manage Salary =====
    ['SAL-001','Manage Salary','Structure','SAL','High','Functional','Create salary structure (daily rate)','Employee exists','1. Set daily rate\n2. Save','Salary structure saved','—'],
    ['SAL-002','Manage Salary','Edit','SAL','High','Functional','Edit daily rate','Existing salary','1. Change daily rate\n2. Save','New rate applies going forward','—'],
    ['SAL-003','Manage Salary','History','SAL','High','Functional','Salary history retained','Employee with rate changes','1. View salary history\n2. Inspect prior payslips','Past payslips keep old rate; history retained','—'],
    ['SAL-004','Manage Salary','Compute','SAL','High','Functional','Daily rate to period pay','Daily rate set','1. Compute payroll','Period pay derived from daily rate × worked days','—'],
    ['SAL-005','Manage Salary','RBAC','SAL','High','Security','Only HR/Owner manage salary','Logged in as Employee','1. As Employee, attempt salary management','Blocked','—'],
    ['SAL-006','Manage Salary','Effective Date','SAL','Medium','Functional','Salary effective date','New rate with effective date','1. Set effective date in future\n2. Compute before/after','Rate applies from effective date','—'],

    // ===== 10. Benefits & Deductions =====
    ['BEN-001','Benefits & Deductions','SSS','BEN','High','Functional','SSS contribution per bracket','Employee salary in a bracket','1. Compute payroll','SSS contribution matches bracket','—'],
    ['BEN-002','Benefits & Deductions','PhilHealth','BEN','High','Functional','PhilHealth contribution per bracket','Employee salary in a bracket','1. Compute payroll','PhilHealth contribution matches bracket','—'],
    ['BEN-003','Benefits & Deductions','Pag-IBIG','BEN','High','Functional','Pag-IBIG contribution per bracket','Employee salary in a bracket','1. Compute payroll','Pag-IBIG contribution matches bracket','—'],
    ['BEN-004','Benefits & Deductions','Net','BEN','High','Functional','Contributions deducted from gross','All contributions set','1. Compute payroll','Contributions deducted; net = gross − deductions','—'],
    ['BEN-005','Benefits & Deductions','Policies','BEN','Medium','Functional','Contribution policies configurable','Admin access','1. Edit contribution policy\n2. Save','Policy saved; applied by ContributionEngine','—'],
    ['BEN-006','Benefits & Deductions','Records','BEN','Medium','Functional','Deduction records per employee','Employee exists','1. Add a deduction record\n2. Save','Record saved; reflected in payroll','—'],
    ['BEN-007','Benefits & Deductions','Filters','BEN','Medium','Functional','Benefits deduction filter','Multiple deductions','1. Use filter in Benefits Deduction','List filtered correctly','—'],
    ['BEN-008','Benefits & Deductions','Brackets','BEN','Medium','Functional','Bracket update applies forward','Existing brackets','1. Update a bracket\n2. Compute future payroll','Future payrolls use new bracket; past unaffected','—'],
    ['BEN-009','Benefits & Deductions','13th Month','BEN','Medium','Functional','13th-month rule applied','Eligible employee','1. Compute 13th-month','Computed per 13th-month rule','—'],
    ['BEN-010','Benefits & Deductions','RBAC','BEN','High','Security','RBAC on benefits','Logged in as Employee','1. As Employee, attempt benefits management','Blocked','—'],
    ['BEN-011','Benefits & Deductions','SSS Brackets','BEN','High','Functional','SSS bracket ranges follow Circular 2024-006 (MSC − 250 to MSC + 249.99)','Migration 20261120000029 applied; PH-STATUTORY-2026 active; employees at ₱470 (Checker/Warehouse), ₱460 (Laborer), ₱700 (HR Head)','1. Compute payroll whose pay date is the last Friday of the month\n2. Open each payslip\'s SSS deduction','EEMR 12,259.17 → MSC 12,500 (EE 625.00); 11,998.33 → MSC 12,000 (EE 600.00); 18,258.33 → MSC 18,500 (EE 925.00). Total EE contributions 1,131.48 / 1,099.96 / 1,581.46','Payroll_Calculations_by_Position.pdf; SSS Circular 2024-006'],
    ['BEN-012','Benefits & Deductions','SSS EC Premium','BEN','High','Functional','SSS employer share includes the EC premium (₱10 below MSC 15,000, ₱30 from 15,000); employee share unchanged','Migration 20261121000035 applied; PH-STATUTORY-2026 active; employees at ₱470 (MSC 12,500) and ₱700 (MSC 18,500)','1. Open Benefits → policy PH-STATUTORY-2026 and check the SSS table\n2. Compute payroll whose pay date is the last Friday of the month\n3. Open Benefits deductions for SSS','Policy table column reads "Employer share (incl. EC)": MSC 14,500 → 1,460.00, MSC 15,000 → 1,530.00. MSC 12,500 employer 1,260.00 (EE 625.00); MSC 18,500 employer 1,880.00 (EE 925.00). Employee SSS and net pay same as BEN-011. Approved runs keep their old figures.','SSS Circular 2024-006; migration 20261121000035'],

    // ===== 11. Reports =====
    ['REP-001','Reports','Payroll Report','REP','High','Functional','Payroll report per period/branch','Approved payroll','1. Open payroll report\n2. Generate','Report generated for period/branch','—'],
    ['REP-002','Reports','Attendance Report','REP','High','Functional','Attendance report','Attendance imported','1. Open attendance report\n2. Generate','Report generated','—'],
    ['REP-003','Reports','Request Report','REP','Medium','Functional','Request report','Requests exist','1. Open request report\n2. Generate','Report generated','—'],
    ['REP-004','Reports','Contribution Report','REP','Medium','Functional','Contribution report','Payroll with contributions','1. Open contribution report\n2. Generate','Report generated','—'],
    ['REP-005','Reports','13th Month Report','REP','Medium','Functional','13th-month report','13th-month data','1. Open 13th-month report\n2. Generate','Report generated','—'],
    ['REP-006','Reports','Transaction Slips','REQ073','High','Functional','Generate transaction slips','Approved payroll period','1. Open Transaction Slips\n2. Select period\n3. Generate','Slips generated per employee for the period','—'],
    ['REP-007','Reports','Transaction Slips','REQ073','High','Functional','Print mode = data-only overlay','Slips generated','1. Open Print Preview (?print=1)','Only fill-in data prints; no form chrome (header/panels/labels/lines/grid)','—'],
    ['REP-021','Reports','Transaction Slips','REQ073','Medium','UI','Print preview replicates the BDO Cash Transaction Slip template','Slips generated','1. Open Print Preview (?print=1)
2. Compare on screen against the BDO Network Bank slip
3. Open browser print preview','Screen shows the full BDO slip (blue header, checkboxes, Account/Payor/Company/Subscriber/Borrower fields, Date, Denomination/Pieces/Amount grid, Total Amount) laid out like the template with data filled in; printed output is data only at the same mm coordinates','—'],
    ['REP-008','Reports','Transaction Slips','REQ073','High','Functional','Data positions preserved for pre-printed slip','Print preview open','1. Compare data positions to physical BDO slip','Data lands on the form fields at the same coordinates','—'],
    ['REP-009','Reports','Transaction Slips','REQ073','High','Functional','Savings/Current tick per account type','Employees with savings & current accounts','1. Generate slips for each\n2. Print preview','Correct box ticked (Savings or Current) per account type','—'],
    ['REP-010','Reports','Transaction Slips','REQ073','Medium','Negative','Missing bank account → blank fields','Employee without bank details','1. Generate slip','Account fields blank; missing-bank notice','—'],
    ['REP-011','Reports','Export','REQ076','Medium','Functional','Reports export as digital files','Report generated','1. Export report (CSV)','Digital file exported (REQ076)','—'],
    ['REP-012','Reports','Payslip Report','REP','Medium','Functional','HR payslip report per employee','Approved payroll','1. Open payslip report','Per-employee payslip card shown','—'],
    ['REP-013','Reports','Employee List','REP','Low','Functional','Employee list report','Employees exist','1. Open employee list report','Report generated','—'],
    ['REP-014','Reports','Print/Export','REP','Medium','Functional','Reports print/export','Any report','1. Print\n2. Export','Print and export succeed','—'],
    ['REP-015','Reports','Payslip Bulk','REP','Medium','Functional','Bulk print payslips','Approved payroll','1. Bulk print payslips','All employee payslips print','—'],
    ['REP-016','Reports','Audit Trail','REP','Medium','Functional','Audit Trail under Reports','Audit data exists','1. Open Reports → Audit Trail','Audit trail accessible under Reports','—'],
    ['REP-017','Reports','Audit Trail','REP','Low','UI','Audit trail row numbering','Audit entries exist','1. Open audit trail','Rows numbered sequentially','—'],
    ['REP-018','Reports','Preview','REP','Medium','Functional','Report preview before print','Report generated','1. Open preview','Preview renders before printing','—'],
    ['REP-019','Reports','RBAC','REP','High','Security','Employee cannot access HR reports','Logged in as Employee','1. As Employee, attempt HR reports','Blocked','—'],
    ['REP-020','Reports','Deductions, Filter & MM/DD/YY','REP','Medium','Functional','Total Deductions summary cards, per-employee payroll report filter, working Export button & MM/DD/YY dates','Computed/approved payroll run; attendance with cancelled batch','1. Open /hr/reports and /hr/reports/payslips\n2. Filter payroll report by employee and click Export CSV/PDF\n3. Check attendance report & PDF date formats','Total Deductions cards match Gross - Net; employee filter scopes breakdown; Export downloads CSV/PDF via GET /hr/reports/export; cancelled batches excluded; all dates formatted MM/DD/YY','Defense P1 #9'],
    ['REP-022','Reports','Audit Trail','REP','High','Functional','Audit Trail loads when an entry holds numeric JSON values','A manual payroll adjustment exists (audit event payroll_adjusted, amount stored as a JSON number)','1. As Owner and as HR Head, open Reports → Audit Trail\n2. Page through all pages\n3. Filter Event = payroll_adjusted','Every page loads (no "Something Went Wrong"); the adjustment reads "Manual deduction of ₱100 added. Reason: …"','Fixed 2026-11-21 (TypeError under strict_types)'],

    // ===== 12. Employee Self-Service =====
    ['PORT-001','Employee Self-Service','Attendance','REQ074','High','Functional','Employee views own attendance','Employee logged in','1. Open portal → attendance','Own attendance shown only','—'],
    ['PORT-002','Employee Self-Service','Schedule','REQ074','High','Functional','Employee views own work schedule','Employee logged in','1. Open portal → schedule','Own schedule shown','—'],
    ['PORT-003','Employee Self-Service','Payslips','REQ074','High','Functional','Employee views own payslips','Approved payslips exist','1. Open portal → payslips','Own payslips listed','—'],
    ['PORT-004','Employee Self-Service','Payslips','REQ074','High','Functional','Employee downloads own payslip PDF','Approved payslip exists','1. Download payslip','PDF downloaded; content correct','—'],
    ['PORT-005','Employee Self-Service','Requests','REQ074','High','Functional','Employee submits requests','Employee logged in','1. Submit leave/overtime/cash advance','Request created; status Pending','—'],
    ['PORT-006','Employee Self-Service','Requests','REQ074','Medium','Functional','Employee tracks own request status','Submitted request','1. Open request tracking','Status visible (Pending/Approved/Cancelled)','—'],
    ['PORT-007','Employee Self-Service','RBAC','REQ074','High','Security','Employee cannot view others data','Logged in as Employee','1. Attempt to access another employee record','Scoped; cannot view others data','—'],
    ['PORT-008','Employee Self-Service','Leave','REQ074','Medium','Functional','Employee views leave balance','Employee with leave balance','1. Open portal → leave balance','Own leave balance shown','—'],
    ['PORT-009','Employee Self-Service','RBAC','REQ074','High','Security','Payslip download unauthorized for others','Logged in as Employee A','1. Attempt to download Employee B payslip','Blocked','—'],
    ['PORT-010','Employee Self-Service','Account','REQ074','Medium','Negative','Unlinked employee account clear error','Account not linked to employee','1. Log in / access portal','Clear error (UnlinkedEmployeeAccountException) shown','—'],
    ['PORT-011','Employee Self-Service','Access control','REQ074','High','Security','Opening another employee\'s payslip returns a real 403 page','Employee A signed in; payslip id that belongs to employee B','1. GET /employee/payslips/{B id}
2. Also .../print and .../pdf','HTTP status 403 (not 200); stand-alone Access Denied page naming the Employee role, with Go to my dashboard; payslip data not shown; no "Undefined variable $roleName" warning in the app log','Fixed 2026-11-22 (ViewRenderer::forbidden)'],

    // ===== 13. Cross-cutting / System =====
    ['SYS-001','System / Cross-cutting','Audit','SYS','High','Functional','Audit trail logs key actions','System in use','1. Perform login/payroll/edit actions\n2. Open audit trail','Key actions logged with user/time/action','—'],
    ['SYS-002','System / Cross-cutting','Audit','SYS','Low','UI','Audit trail row numbering','Audit entries exist','1. Open audit trail','Rows numbered sequentially','—'],
    ['SYS-003','System / Cross-cutting','Timezone','SYS','Medium','Functional','Asia/Manila at boundaries, UTC stored','N/A','1. Inspect stored timestamps vs displayed','Stored instants UTC; business boundary displays Asia/Manila','—'],
    ['SYS-004','System / Cross-cutting','Money','SYS','Medium','Functional','Money integer-centavo / decimal-string','Payroll computed','1. Inspect money values','Money computed in integer centavos / validated decimal strings','—'],
    ['SYS-005','System / Cross-cutting','Localization','SYS','Low','Functional','Dates MM/DD/YY, 24h, English','N/A','1. Inspect date/time displays','Dates MM/DD/YY; time 24-hour; English UI','—'],
    ['SYS-006','System / Cross-cutting','Database','SYS','Medium','Data','MySQL strict mode / utf8mb4','N/A','1. Insert invalid/oversized data','Strict mode rejects invalid; utf8mb4 enforced','—'],
    ['SYS-007','System / Cross-cutting','Database','SYS','Medium','Data','Phinx migrations apply cleanly','N/A','1. Run phinx migrate','Migrations apply without error; schema matches canonical v1.1','—'],
    ['SYS-008','System / Cross-cutting','Session','SYS','Medium','Security','DB-backed sessions','Logged in','1. Inspect sessions table','Session persisted in DB via SessionHandlerInterface','—'],
    ['SYS-009','System / Cross-cutting','CSRF','SYS','High','Security','CSRF on all state-changing requests','N/A','1. Submit POSTs without token','All state-changing POSTs rejected without valid CSRF','—'],
    ['SYS-010','System / Cross-cutting','Security','SYS','High','Security','SQL injection protection','N/A','1. Inject SQL in inputs','Parameterized queries prevent injection; no leakage','—'],
    ['SYS-011','System / Cross-cutting','Security','SYS','High','Security','XSS output escaping','N/A','1. Submit script tags in inputs','All view output escaped; no script executes','—'],
    ['SYS-012','System / Cross-cutting','Config','SYS','Medium','Security','Errors hidden in production','APP_ENV=production','1. Trigger an error','No stack trace shown; errors suppressed in prod','—'],
    ['SYS-013','System / Cross-cutting','Health','SYS','Low','Functional','Health endpoint responds','App running','1. GET /health','Health endpoint returns OK','—'],
    ['SYS-014','System / Cross-cutting','Middleware','SYS','Low','Functional','Request ID / middleware','N/A','1. Make a request\n2. Inspect headers/logs','Request ID propagated','—'],
    ['SYS-015','System / Cross-cutting','Audit','SYS','Low','UI','Audit Trail back button','Audit trail open','1. Click back button on Audit Trail','Returns to previous/Reports view','—'],
    ['SYS-016','System / Cross-cutting','UI','SYS','Low','UI','Validation toast','Form with validation','1. Submit invalid form','Validation toast shown','—'],
    ['SYS-017','System / Cross-cutting','UI','SYS','Low','UI','App version display','N/A','1. Inspect footer/version','App version displayed','—'],
    ['SYS-018','System / Cross-cutting','Confirmations','SYS','High','Functional','Action confirmations use the on-page box and work where browser pop-ups are blocked','Any browser, including the Claude desktop browser pane (blocks window.confirm)','1. HR → Leave Credits → an employee → Usage 0.5 day + note → Apply Adjustment\n2. Click Cancel in the box, then repeat and click Confirm\n3. Payroll run → Cancel Payroll Run with the reason empty\n4. Add Employee → Save','Box shows the action message; Cancel sends nothing; Confirm submits once and the action happens; empty required reason shows "required" before any box; Add Employee asks to confirm the login email ("Yes, create employee"), Cancel returns to the email field; no window.confirm() pop-ups remain','Fixed 2026-11-21 (assets/confirm-dialog.js, 27 confirmations)'],
    ['SYS-019','System / Cross-cutting','Error logging','SYS','High','Non-Functional','Crashes and caught failures are written to the app error log with a reference shown to the user','Server access; storage/private/logs writable by www-data','1. Trigger a 500 (e.g. a deliberately broken page on a test copy)\n2. Note the Reference on the 500 page\n3. grep that reference in storage/private/logs/app-error.log\n4. Make a caught failure (e.g. Resend verification email with mail down)','500 page shows "Reference: <12 hex>" and the X-Error-Reference header; the log has one line "WBPMS UNCAUGHT ref=<same>" with method, path (no query string), user, role, IP, exception class, message, file:line and trace without arguments; the caught failure logs "WBPMS CAUGHT (<place>)"; PHP warnings also land in the same file; nothing under public/','Added 2026-11-21 (Infrastructure/Logging/ErrorLog.php)'],
    ['SYS-020','System / Cross-cutting','HTTP status','SYS','Medium','Non-Functional','Pages rendered after an error keep their error status','Signed in','1. Open a missing record, e.g. /hr/employees/999999999
2. Submit the Users form with an invalid value
3. Open a normal page','Step 1 returns 404, step 2 returns 422 (form still shown with errors), step 3 returns 200; the browser shows the same pages as before','Fixed 2026-11-22 (ViewRenderer::responseStatus; render() no longer forces 200)'],
    ['SYS-021','System / Cross-cutting','Tables','SYS','High','UI','Long tables are paginated, sortable and scroll inside their card','Signed in as HR Head; a list with more than 25 rows (e.g. User Management as Owner, HR Employees)',"1. Open the list; check the bar under the table\n2. Change Rows per page to 10 and go to page 2; reload\n3. Click a column header twice\n4. Type in a table's own search box (e.g. Work Schedule → Search schedules)\n5. Narrow the window (or phone width) on Attendance and Work Schedule\n6. Print a paginated list",'Bar shows "Showing 1–25 of N", page buttons and Rows per page (10/25/50/100); page and sort survive reload (same tab); header shows an arrow and sorts ascending then descending, blanks last, dates/amounts sort by value; search filters across all pages and resets to page 1 ("No matching records." when none); wide tables scroll sideways inside the card, never the whole page; cells over ~70 chars show 3 lines + "more" (click to expand); printing shows every row','Added 2026-11-22 (assets/ui-enhance.js + ui-enhance.css)'],
    ['SYS-022','System / Cross-cutting','Filters','SYS','High','Functional','Search, filter, sort and date pickers apply automatically (no Filter button)','Signed in; any filter bar (HR Attendance, Employees, Requests, Audit Trail, Users, Employee My Attendance)',"1. Type part of a name in a filter Search box and stop typing\n2. Change a Branch/Status select\n3. Pick a From date then a To date\n4. Pick a To date earlier than the From date (type it)\n5. Click Clear",'No Filter/Apply button is shown; results update about half a second after typing stops and the cursor stays in the search box; selects apply at once; picking From opens the To calendar and the page updates once after To is picked; an end date before the start is refused with a message and nothing is sent; Clear shows the default view','Added 2026-11-22 (assets/ui-enhance.js)'],
    ['SYS-023','System / Cross-cutting','Date retention','SYS','High','Functional','Picked dates stay after reload and when the page is opened again','Signed in as HR Head',"1. HR → Attendance → By Date Range → pick 11/12/26–11/18/26\n2. Click Attendance in the sidebar (no query string) or reload\n3. Click Clear, then open Attendance again\n4. HR → Requests → New → choose Leave, pick a start date, reload the page (do not submit)\n5. Submit a request, then open the New form again\n6. Sign in as another user on the same browser",'Step 2 shows the same 11/12/26–11/18/26 range; after Clear the default (this month) shows and stays; step 4 keeps the picked start date after reload; after a successful submit the form starts empty again; another account never sees the first user\'s dates','Added 2026-11-22 (assets/ui-enhance.js; localStorage per account for filters, sessionStorage for form drafts)'],
    ['SYS-024','System / Cross-cutting','Calendar','SYS','Medium','UI','Date fields open the WBPMS calendar, not the browser\'s generic picker','Any page with a date or month field',"1. Click a date field (e.g. Attendance From)\n2. Use arrow keys, PageUp/PageDown, Enter, Esc\n3. Click the month title (twice) to jump by month/year\n4. In a From/To pair use This cut-off / Last cut-off / This month\n5. Open a Month field (Attendance → By Month)\n6. Type a date directly into the field",'Branded calendar opens under the field (above it if no room, fits a phone screen); Saturday column marked as rest day; today has a dot; selected day filled; range between From and To shaded, days before From disabled in the To calendar; This cut-off fills Friday–Thursday of the current week; Month fields show a 12-month grid; keyboard works; typing still works; the browser\'s own picker never opens; min/max dates are disabled','Added 2026-11-22 (assets/ui-enhance.js)'],
    ['SYS-025','System / Cross-cutting','Error messages','SYS','High','Non-Functional','Errors are friendly and consistent; no "500 Internal Server Error" or SQL text reaches the browser','Test copy where a database error can be forced (e.g. duplicate key) and a page can be made to crash',"1. Force a database error on a save (e.g. duplicate schedule name via direct DB change)\n2. Force an uncaught exception\n3. Open a missing record (/hr/employees/999999999)\n4. Open an HR page as Employee\n5. Call a JSON endpoint that fails",'Step 1 shows a toast/field message "Something went wrong and your request could not be completed… (reference xxxxxxxxxxxx)." with no SQLSTATE, table or column names; step 2 shows the branded "Something went wrong on our side" page with a reference code, no status number, no "Internal Server Error", no file paths (HTTP status stays 500); 403/404/500 pages share one look; 404 shows inside the app layout; JSON error code is UNEXPECTED_ERROR with a reference; the log has the full detail once','Added 2026-11-22 (Http/View/UserMessage.php, errors/_page.php, 64 controller catch sites)'],
    ['SYS-026','System / Cross-cutting','Labels','SYS','Low','UI','Labels and headers use one wording and Title Case; stored codes are shown as words','Signed in as each role',"1. Check column headers and form labels on Employees, Requests, Payroll, Benefits, Users\n2. Check request type lists (HR/Owner/Employee), holiday type badges, User Management role column, sidebar role under the name",'Headers/labels in Title Case ("Effective From", "Time In", "Net Pay", "HR Note"); one name per field ("Employee No.", "Job Position", "Daily Rate (₱)"); filter bars use "Clear"; "Cash Advance", "HR Head", "Business Owner", "Special Non-Working" instead of CashAdvance / HRHead / BusinessOwner / SpecialNonWorking','Fixed 2026-11-22 (149 labels; Formatter::label)'],
    ['SYS-027','System / Cross-cutting','Icons','SYS','Medium','UI','All icons are line SVG icons in the app colours; no emoji anywhere','Signed in as each role; any browser/OS (Windows, Android, iPhone)',"1. Look at the sidebar, top bar, search boxes and Logout\n2. Open Reports, Settings, Requests (HR/Owner/Employee) and a payslip / payroll run (Print, PDF, CSV buttons)\n3. Owner → User Management → Actions menu → Deactivate (then Cancel)\n4. Trigger a success and an error message\n5. Run vendor/bin/phpunit --filter NoEmojiIconsTest",'Every icon is a thin line icon that follows the text colour (copper on hover/active, white on primary buttons) and looks the same on every device; no 🖨 ⬇ 💵 ⚙ ♟ ☰ ✓ ↻ or other emoji/symbols; Back links use a chevron; confirm box and toasts show line icons; date-range arrows in text and BDO slip ticks unchanged; the test passes','Fixed 2026-11-22 (Http/View/Icon.php, NoEmojiIconsTest)'],
    ['AUTH-025','Login & Authentication','Login','AUTH','Medium','Functional','Failed login keeps the typed email (REQ001)','Logged out','1. Type a valid email and a wrong password
2. Click Login','"Invalid email or password." is shown, the email field still holds the typed email and the cursor is in the password field','Fixed 2026-11-22 (evaluation remarks)'],
    ['AUTH-026','Login & Authentication','Change Password','AUTH','High','Functional','Any signed-in user can change their own password (REQ002)','Signed in as Owner, HR Head or Employee','1. Sidebar → Change Password
2. Enter a wrong current password
3. Enter the right current password, a weak new password
4. Enter a strong new password twice
5. Sign out and sign in with the old, then the new password','Step 2: "Your current password is not correct."; step 3: strength rule message; step 4: "Your password was changed."; old password refused, new password signs in; audit_logs has password_change_failed / password_changed','Fixed 2026-11-22 (evaluation remarks)'],
    ['DASH-007','Dashboard','HR Dashboard','DASH','Medium','Functional','HR dashboard cards and Recent Requests show real work (REQ003)','Signed in as HR Head; requests and payroll runs in several states','1. Open HR Dashboard
2. Approve a request (as Owner) and reopen the dashboard
3. Have the Owner return a payroll run','"Requests to Review" counts Pending + Returned; "Payroll Runs to Finish" counts Draft + Computed + Returned and shows "N returned by Owner"; Recent Requests lists the latest 10 in any status (approved ones stay, with a View button); header matches other pages with the date as MM/DD/YY','Fixed 2026-11-22 (evaluation remarks)'],
    ['USER-014','User Management','Edit user','USER','Medium','Functional','Username and email are validated on the server (REQ005)','Signed in as Owner','1. Edit a user; send username "ab", "juan cruz", or an email "juan@" (e.g. with browser checks disabled)','The save is refused with "Username must be 3 to 50 characters." / "Username may use letters, numbers, dots, hyphens and underscores only" / "Enter a valid email address"; nothing changes','Fixed 2026-11-22 (evaluation remarks)'],
    ['EMP-027','Employee Management','Search','EMP','High','Functional','Search by full name in any order (REQ012)','Signed in as HR Head','1. Employees → search "Juan Dela Cruz", then "Dela Cruz, Juan", then "cruz juan"
2. Same full name in Attendance, Requests, Salary, Benefits and Reports filters','The employee is found every time; words may be in any order and match first name, middle initial, last name or employee number','Fixed 2026-11-22 (evaluation remarks)'],
    ['EMP-028','Employee Management','Bank details','EMP','Medium','Functional','Bank account number needs 10–16 digits (REQ014)','Signed in as HR Head','1. Employee → Bank Account → account number "12"
2. "12AB345678"
3. "0012-3456-7890"','Steps 1–2 refused with a message (digits only; 10 to 16 digits); step 3 saved','Fixed 2026-11-22 (evaluation remarks)'],
    ['EMP-029','Employee Management','Transfer / layout','EMP','Medium','UI','Transfer date shows the earliest allowed day; Edit buttons wrap (REQ015, REQ010)','Signed in as HR Head','1. Employee → Transfer Branch; open the calendar
2. Edit Employee on a phone-width screen','Hint shows "Earliest date: MM/DD/YY (the current branch assignment starts …)"; days before it are disabled; default is today or the earliest day; Save/Cancel/Documents/Bank/Transfer/Archive wrap onto new lines, no sideways scroll','Fixed 2026-11-22 (evaluation remarks)'],
    ['ATT-030','Attendance','Adjust / Manual','ATT','Medium','Functional','Worked, late and undertime update while typing; 24-hour time picker (REQ019, REQ020)','Signed in as HR Head','1. Attendance → Manual entry or Adjust
2. Click Time In: pick 07 then :25
3. Type 1530 in Time Out and leave the field','Clock picker with hours 00–23 and 5-minute steps; Time In becomes 07:25; Time Out becomes 15:30; "Will be recorded" shows worked/late/undertime from the assigned schedule and matches what Save records','Fixed 2026-11-22 (evaluation remarks)'],
    ['SCH-016','Work Schedule','Validation','SCH','Low','Functional','Schedule status, overtime setting and notes are checked on the server (REQ024/025)','Signed in as HR Head','1. Send a schedule with status "Deleted" or overtime_allowed "5" (tampered request)
2. Notes longer than 1,000 (schedule) / 255 (assignment) characters','Refused with a field message; the form fields limit the note length','Fixed 2026-11-22 (evaluation remarks)'],
    ['REQ-022','Request Management','HR cancel','REQ','High','Functional','HR can cancel Pending, Returned and pre-approved requests (REQ033)','Signed in as HR Head; requests in those states','1. Requests list → Cancel on a request
2. Cancel a request already pre-approved (waiting for the Owner) with a note
3. Try without a note','Cancel opens the request at the cancel form; pre-approved requests also show the form; with a note the request becomes Cancelled and leaves the Owner queue; without a note it is refused','Fixed 2026-11-22 (evaluation remarks)'],
    ['PAY-030','Payroll','Payroll run page','PAY','Medium','UI','Run actions at the top; payslip View button (REQ056, REQ057)','Signed in as HR Head','1. Open a Computed run
2. Open an Approved run and click View on an employee','Compute / Submit for Approval / Cancel Payroll Run appear above the tables; View opens the full payslip in the same tab with a back link; Print and PDF still work','Fixed 2026-11-22 (evaluation remarks)'],
    ['SAL-007','Manage Salary','Effective date','SAL','High','Functional','New salary date: earliest day shown, real dates only, rate ceiling (REQ062/063)','Signed in as HR Head','1. Add Salary Record → choose an employee with a current salary
2. Edit Salary on an existing record
3. Enter 2026-02-31 or a daily rate of 2,000,000','Hint shows the earliest date (day after the current salary starts) and earlier days are disabled; Edit defaults to today (or the earliest day), not the current record\'s date; impossible dates and rates above ₱1,000,000.00 are refused with a clear message (MM/DD/YY)','Fixed 2026-11-22 (evaluation remarks)'],
    ['REQ-023','Request Management','Cash Advance Date','REQ','High','Functional','Cash advance has a date; HR can record an advance from a past month','Signed in as HR Head (also Employee portal); employee hired before the date','1. New Request → Cash Advance: date defaults to today
2. Enter 06/05/26, ₱1,000 and submit; open the request (HR and Owner) and Cash Advance Balances
3. Try a date before the hire date, more than 31 days ahead, or empty','Step 2 saved; detail pages and Cash Advance Balances show Cash Advance Date 06/05/26; step 3 refused with "cannot be before the employee\'s hire date (MM/DD/YY)", "cannot be more than 31 days from today" or "Cash advance date is required"','Added 2026-11-22 (owner request: past-month biometric payroll)'],
    ['PAY-031','Payroll','Cash Advance Repayment','PAY','High','Functional','Repayment follows the cash advance date, not the approval day','Employee with an approved ₱1,000 cash advance dated 11/20/26; open payroll runs for a June week and the week of 11/20','1. Compute the June run
2. Compute the 11/20 week run
3. Approve a ₱500 advance dated 06/05/26 (balance ₱500) and compute two open runs after it before approving either','Step 1: no Cash advance repayment line; step 2: ₱500.00 deducted; step 3: only the first computed run takes the ₱500, the second takes ₱0 (no double charge before approval); balance drops only on Owner approval','Added 2026-11-22'],
    ['REQ-024','Request Management','Leave Year','REQ','High','Functional','Sick leave uses the credit of its own year','Employee with 4.0 credits in 2025 and 4.0 in 2026; 2025 holidays entered in Settings','1. File and fully approve sick leave for 2 working days in 03/2025
2. Leave Credits: view 2025 and 2026
3. File leave from 12/30/25 to 01/02/26','2025 balance 4.00 → 2.00, 2026 stays 4.00; balance messages name the year; step 3 refused: "cannot cross from 2025 into 2026 ... File one request up to 12/31 and another from 01/01"','Fixed 2026-11-22 (approved leave always used the current year\'s credit)'],
];

/* ----------------------------------------------------------------------
   BUILD WORKBOOK
   ---------------------------------------------------------------------- */
$spreadsheet = new Spreadsheet();

$columns = [
    'TC ID','Module','Feature / Area','Requirement Ref','Priority','Test Type',
    'Test Case Title','Preconditions','Test Steps','Expected Result','Test Data',
    'Status','Actual Result','Tester','Date Tested','Notes',
];
$colCount = count($columns); // 16
$lastColLetter = Coordinate::stringFromColumnIndex($colCount); // P
$lastRow = 1 + count($cases);

$colLetters = [];
for ($i = 1; $i <= $colCount; $i++) {
    $colLetters[$i] = Coordinate::stringFromColumnIndex($i);
}

/* ---------- Sheet 1: Cover & Instructions ---------- */
$cover = $spreadsheet->getSheet(0);
$cover->setTitle('Cover & Instructions');
$cover->getColumnDimension('A')->setWidth(24);
foreach (['B','C','D','E','F'] as $c) {
    $cover->getColumnDimension($c)->setWidth(22);
}

$cover->mergeCells('A1:F2');
$cover->setCellValue('A1', 'WBPMS — Web-Based Payroll Management System');
$cover->getStyle('A1')->applyFromArray([
    'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A3A60']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);

$cover->mergeCells('A3:F3');
$cover->setCellValue('A3', 'Regression Test Suite');
$cover->getStyle('A3')->applyFromArray([
    'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '1A3A60']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

$meta = [
    ['Document Version', '1.0'],
    ['Generated', date('Y-m-d')],
    ['System Under Test', 'WBPMS (Light Diamond Enterprises)'],
    ['Source Repositories', 'Dev: romarsares/WBPMS (branch WBPMS-dev)  |  Prod: lightdiamond0123-rgb/wbpms (branch main)'],
    ['Scope', 'End-to-end regression across all WBPMS modules: functional, security/RBAC, business rules, negative, boundary, UI.'],
    ['Modules Covered', 'Login & Authentication; Dashboard; User Management; Employee Management; Work Schedule; Attendance; Request Management; Payroll; Manage Salary; Benefits & Deductions; Reports; Employee Self-Service; System / Cross-cutting.'],
    ['How to Use', "Open the 'Regression Test Cases' sheet. Execute each test case. Use the dropdowns to set Priority, Test Type, and Status. Record Actual Result, Tester, and Date Tested. The 'Summary' sheet shows live counts via formulas."],
    ['Status Legend', 'Not Run | Pass | Fail | Blocked | In Progress'],
    ['Priority Legend', 'High | Medium | Low'],
    ['Test Type Legend', 'Functional | Security | Negative | Boundary | UI | Integration | Data'],
    ['Requirement Refs', 'REQ IDs from the canonical spec where defined (e.g., REQ047, REQ073, REQ074-REQ082, REQ076). Cases without a specific REQ ID reference their module/feature.'],
    ['Notes', 'Transaction slip print mode (REQ073) currently emits a data-only overlay intended to print onto a physical pre-printed BDO Cash Transaction Slip.'],
];
$row = 5;
foreach ($meta as $item) {
    $cover->setCellValue('A' . $row, $item[0]);
    $cover->mergeCells('B' . $row . ':F' . $row);
    $cover->setCellValue('B' . $row, $item[1]);
    $cover->getStyle('A' . $row)->applyFromArray(['font' => ['bold' => true]]);
    $cover->getStyle('A' . $row . ':F' . $row)->applyFromArray([
        'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D0D0D0']]],
    ]);
    // estimate row height for wrapped text
    $len = strlen($item[1]);
    $cover->getRowDimension($row)->setRowHeight(min(120, max(18, ceil($len / 90) * 15)));
    $row++;
}

/* ---------- Sheet 2: Regression Test Cases ---------- */
$cs = $spreadsheet->createSheet(1);
$cs->setTitle('Regression Test Cases');

// header row
foreach ($columns as $i => $name) {
    $cell = $colLetters[$i + 1] . '1';
    $cs->setCellValue($cell, $name);
}
$cs->getStyle('A1:' . $lastColLetter . '1')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A3A60']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B0B0B0']]],
]);
$cs->getRowDimension(1)->setRowHeight(30);

// data rows
foreach ($cases as $idx => $case) {
    $r = $idx + 2; // start at row 2
    $full = array_merge($case, ['Not Run', '', '', '', '']); // 16 cols
    foreach ($full as $ci => $val) {
        $cs->setCellValue($colLetters[$ci + 1] . $r, $val);
    }
}

// column widths
$widths = [12, 20, 24, 14, 11, 14, 40, 38, 52, 46, 26, 14, 32, 14, 14, 26];
foreach ($widths as $i => $w) {
    $cs->getColumnDimension($colLetters[$i + 1])->setWidth($w);
}

// data cell styling (wrap, top/left, borders, alt fill)
$cs->getStyle('A2:' . $lastColLetter . $lastRow)->applyFromArray([
    'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E0E0E0']]],
    'font' => ['size' => 10],
]);
// alternating row fill
for ($r = 2; $r <= $lastRow; $r++) {
    if ($r % 2 === 0) {
        $cs->getStyle('A' . $r . ':' . $lastColLetter . $r)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F7FA']],
        ]);
    }
}

// freeze + autofilter
$cs->freezePane('C2');
$cs->setAutoFilter('A1:' . $lastColLetter . $lastRow);

// data validation dropdowns (Priority E, Test Type F, Status L)
function makeDV(string $list): DataValidation
{
    $dv = new DataValidation();
    $dv->setType(DataValidation::TYPE_LIST);
    $dv->setFormula1('"' . $list . '"');
    $dv->setAllowBlank(true);
    $dv->setShowErrorMessage(true);
    $dv->setShowInputMessage(true);
    return $dv;
}

$dvPriority = makeDV('High,Medium,Low');
$dvType     = makeDV('Functional,Security,Negative,Boundary,UI,Integration,Data');
$dvStatus   = makeDV('Not Run,Pass,Fail,Blocked,In Progress');
$dvStatus->setPromptTitle('Status');
$dvStatus->setPrompt('Select the execution status.');
$dvStatus->setErrorTitle('Invalid status');
$dvStatus->setError('Choose a value from the dropdown list.');

for ($r = 2; $r <= $lastRow; $r++) {
    $cs->getCell('E' . $r)->setDataValidation(clone $dvPriority);
    $cs->getCell('F' . $r)->setDataValidation(clone $dvType);
    $cs->getCell('L' . $r)->setDataValidation(clone $dvStatus);
}

/* ---------- Sheet 3: Summary ---------- */
$sm = $spreadsheet->createSheet(2);
$sm->setTitle('Summary');
$sm->getColumnDimension('A')->setWidth(34);
$sm->getColumnDimension('B')->setWidth(16);

$rangeCol = function (string $colLetter) use ($lastRow) {
    return "'Regression Test Cases'!" . $colLetter . '2:' . $colLetter . $lastRow;
};

$sm->mergeCells('A1:B1');
$sm->setCellValue('A1', 'Regression Summary (live)');
$sm->getStyle('A1')->applyFromArray([
    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A3A60']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

$r = 3;
$sm->setCellValue('A' . $r, 'Metric'); $sm->setCellValue('B' . $r, 'Count');
$sm->getStyle('A' . $r . ':B' . $r)->applyFromArray(['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E1F2']]]);

$statusMetrics = [
    ['Total Test Cases', '=COUNTA(' . $rangeCol('A') . ')', null],
    ['Pass', '=COUNTIF(' . $rangeCol('L') . ',"Pass")', 'C6EFCE'],
    ['Fail', '=COUNTIF(' . $rangeCol('L') . ',"Fail")', 'FFC7CE'],
    ['Blocked', '=COUNTIF(' . $rangeCol('L') . ',"Blocked")', 'FFEB9C'],
    ['In Progress', '=COUNTIF(' . $rangeCol('L') . ',"In Progress")', 'BDD7EE'],
    ['Not Run', '=COUNTIF(' . $rangeCol('L') . ',"Not Run")', 'D9D9D9'],
];
$r = 4;
foreach ($statusMetrics as $m) {
    $sm->setCellValue('A' . $r, $m[0]);
    $sm->setCellValue('B' . $r, $m[1]);
    if ($m[2] !== null) {
        $sm->getStyle('B' . $r)->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $m[2]]]]);
    }
    $r++;
}
// % Pass
$sm->setCellValue('A' . $r, '% Pass (of executed)');
$sm->setCellValue('B' . $r, '=IF((B4+B5+B6+B7)>0,(B5)/(B4+B5+B6+B7),0)');
$sm->getStyle('B' . $r)->getNumberFormat()->setFormatCode('0.0%');
$totalRow = 4;
$r += 2;

// By Module
$sm->setCellValue('A' . $r, 'By Module'); $sm->setCellValue('B' . $r, 'Count');
$sm->getStyle('A' . $r . ':B' . $r)->applyFromArray(['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E1F2']]]);
$r++;
$modules = array_values(array_unique(array_map(fn($c) => $c[1], $cases)));
$moduleRange = $rangeCol('B');
foreach ($modules as $mod) {
    $sm->setCellValue('A' . $r, $mod);
    $sm->setCellValue('B' . $r, '=COUNTIF(' . $moduleRange . ',"' . str_replace('"', '""', $mod) . '")');
    $r++;
}
$r++;

// By Priority
$sm->setCellValue('A' . $r, 'By Priority'); $sm->setCellValue('B' . $r, 'Count');
$sm->getStyle('A' . $r . ':B' . $r)->applyFromArray(['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E1F2']]]);
$r++;
$prioRange = $rangeCol('E');
foreach (['High', 'Medium', 'Low'] as $p) {
    $sm->setCellValue('A' . $r, $p);
    $sm->setCellValue('B' . $r, '=COUNTIF(' . $prioRange . ',"' . $p . '")');
    $r++;
}

$sm->getStyle('A1:B' . $r)->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D0D0D0']]],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);

/* ---------- Save ---------- */
$spreadsheet->setActiveSheetIndex(0);
$outPath = __DIR__ . '/WBPMS_Regression_Test_Suite.xlsx';
$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save($outPath);

echo "Generated: " . $outPath . "\n";
echo "Test cases: " . count($cases) . "\n";
echo "Modules: " . count($modules) . "\n";
echo "Size: " . round(filesize($outPath) / 1024, 1) . " KB\n";
