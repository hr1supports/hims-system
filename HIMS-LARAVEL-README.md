# HIMS on Laravel

HIMS (HR1) now runs on **Laravel 12**. The pages and their design are unchanged; the backend
runs inside Laravel, and HR2 / HR3 / HR4 get a proper Laravel REST API under `/api/v1`.

## What is where

| Part | Location |
|---|---|
| HIMS pages (HTML, CSS, JS) | `public/` |
| HIMS page API (`/backend/api.php?resource=...`) | `routes/hims.php` → `app/Http/Controllers/HimsApiController.php` → `app/Legacy/backend/` |
| Careers page with link previews | `routes/hims.php` → `CareersPageController` |
| Integration API for HR2/HR3/HR4 | `routes/api.php` → `app/Http/Controllers/Api/*` |
| Key check for partner systems | `app/Http/Middleware/EnsureIntegrationKey.php` |
| Database models | `app/Models/` (Vacancy, FileExchange, IntegrationRequest, IntegrationKey, User) |
| HIMS settings | `config/hims.php` (values from `.env`) |
| Database setup | `database/migrations/2026_11_04_000000_create_hims_schema.php` + `database/sql/` |
| Uploaded files | `storage/app/uploads/` (resumes, exchange, receipts) |

## Run it on your PC

1. Start MySQL (XAMPP or the MySQL service).
2. In `.env` set the database (the existing `hr3_system` database is used as-is):
   ```
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=hr3_system
   DB_USERNAME=hr3_app
   DB_PASSWORD=your-hr3_app-password
   SESSION_DRIVER=file
   CACHE_STORE=file
   QUEUE_CONNECTION=sync
   ```
3. In the `hims-laravel` folder run:
   ```
   php artisan serve --port=8000
   ```
4. Open http://127.0.0.1:8000/

Your existing database already has every table, so you do **not** need `php artisan migrate`.
For a brand-new empty database, set `DB_USERNAME=root` (a user that can create tables), run
`php artisan migrate`, then switch back to `hr3_app`.

Copy resumes uploaded before the move from `...\hr3_uploads\resumes` into `storage\app\uploads\resumes`.

## Integration API for HR2 / HR3 / HR4 / Administrators

Base URL: `http://<HIMS address>/api/v1`
Authentication: `Authorization: Bearer <key>` (or header `X-Integration-Key: <key>`).
The HIMS Super Admin creates each system's key in **File Exchange > Connections**.
All responses are JSON. Validation errors return HTTP 422 with an `errors` object.

| Method | Path | Who | What |
|---|---|---|---|
| GET | `/vacancies` | anyone | Open job postings |
| GET | `/ping` | partners | Check your key works |
| GET | `/files` | partners | Files HIMS sent you that are waiting (`?status=all` for all) |
| GET | `/files/{id}` | partners | Download a file (marks it Picked up) |
| POST | `/files` | partners | Send a file: multipart `file`, `subject`, optional `note`, `sender`, `reference` (max 10 MB) |
| GET | `/requests` | partners | Your requests (`?status=Pending\|Approved\|Rejected`) |
| GET | `/requests/{reference}` | partners | One request with the decision and remark |
| POST | `/requests` | partners | Send a request: `reference`, `type`, `title`, optional `employee_code`, `employee_name`, `details`, `hours`, `date` (Y-m-d), `requested_by` |
| POST | `/hire-orders` | HR3 only | Send a hire order: `reference`, `title`, `department`, optional `type`, `slots`, `deadline`, `description`, `qualifications`, `requested_by` |
| GET | `/hire-orders/{reference}` | HR3 only | Status of a hire order (Pending / Open / Closed) |

### Example (from another Laravel app)

```php
use Illuminate\Support\Facades\Http;

$hims = Http::withToken(env('HIMS_KEY'))->acceptJson()->baseUrl(env('HIMS_URL').'/api/v1');

$hims->get('/ping')->json();                       // ['partner' => 'HR4', ...]
$hims->post('/requests', [
    'reference' => 'OT-0012', 'type' => 'Overtime', 'title' => 'Overtime for Oct 30',
    'employee_code' => 'EMP-001', 'hours' => 3, 'date' => '2026-10-30',
])->json();
$hims->get('/requests/OT-0012')->json('record.status'); // Pending / Approved / Rejected
$hims->attach('file', file_get_contents($path), 'report.pdf')
     ->post('/files', ['subject' => 'October payroll report'])->json();
```

The older addresses (`/backend/api.php?resource=integration-files`, `integration-requests`,
`hire-order`) still work, so nothing that already connected breaks.

## Data HIMS sends (push)

HIMS also **sends** records to each partner system on its own, as soon as they happen.
Each partner team builds **one POST route** that receives them and gives the HIMS Super Admin:

1. the full address of that route (for example `https://hr2.example.com/api/hims`), and
2. optionally a key. HIMS sends it as `Authorization: Bearer <key>` and `X-Integration-Key: <key>`.

The Super Admin saves them in **File Exchange > Data HIMS sends**, presses **Send a test**, then
**Send all existing records** once. After that, everything new is sent automatically.
Your route must answer with any HTTP 2xx status (for example `200 {"ok":true}`); anything else
counts as failed and HIMS tries again later (button **Retry waiting**, or `php artisan hims:push-retry`).
The same record can arrive twice, so use `event` + `reference` to update instead of inserting twice.

| System | Event | Sent when |
|---|---|---|
| HR2 | `employee.onboarded` | An HR Officer approves an employee's onboarding as complete |
| HR2 | `employee.activated` | An employee account is created, or an account becomes an Employee |
| HR3 | `trainee.account_created` | A trainee account is created for a hired applicant |
| HR3 | `applicant.onboarded` | An HR Officer approves the onboarding as complete |
| HR4 | `claim.decided` | An HR Officer approves or rejects a claim |
| HR4 | `reimbursement.decided` | An HR Officer approves or rejects an employee's reimbursement request |
| HR4 | `payroll.inputs` | An HR Officer presses **Send payroll inputs** for a month |
| Administrators | `applicant.hired` | An applicant's status is set to Hired |
| all | `hims.test` | The **Send a test** button |

Every POST has the same envelope (headers `X-HIMS-Event` and `X-HIMS-Delivery` are also sent):

```json
{
  "event": "applicant.hired",
  "delivery_id": 41,
  "reference": "application-17",
  "source": "HIMS (HR1)",
  "partner": "ADMIN",
  "sent_at": "2026-11-05T09:30:00+08:00",
  "data": { "applicant": { "fullName": "Juan Dela Cruz", "...": "..." } }
}
```

What is inside `data`:

- **applicant.hired**: `applicant` {applicationId, fullName, email, phone, position, department, employmentType, status, source, hasResume, appliedAt, updatedAt}
- **employee.onboarded**: `employee` {employeeCode, firstName, lastName, fullName, email, contact, department, position, hireDate, status, accountRole} and `onboarding` {onboardingId, applicationId, name, email, position, department, startDate, status, completedAt}
- **employee.activated**: `employee` (same fields as above)
- **applicant.onboarded**: `applicant` (as in applicant.hired) and `onboarding`
- **trainee.account_created**: `trainee` {accountId, name, email, department, position, role, active, createdAt, training {program, startDate, expectedEndDate, status, notes}} and `applicant` (or null)
- **claim.decided**: `claim` {claimId, employeeId, employeeName, amount, status, filedAt, encodedBy, decidedBy, hasReceipt}
- **reimbursement.decided**: `reimbursement` {requestId, employeeId, employeeName, department, details, amount, neededBy, status, hrNote, decidedBy, filedAt}
- **payroll.inputs**: {month, periodStart, periodEnd, dayHours, `employees`: [{employeeCode, name, department, position, basicMonthlySalary, workDays, daysPresent, hoursWorked, regularHours, undertimeHours, overtimeApprovedHours, leaveDays, absences, restDays, unverifiedAttendance}]}. HIMS does not compute pay; HR4 does.

Salary goes only to HR4. Passwords and login data are never sent.

### Receiving it (in the partner's Laravel app)

```php
// routes/api.php
Route::post('/hims', function (Illuminate\Http\Request $request) {
    abort_unless(hash_equals((string) env('HIMS_PUSH_KEY'), (string) $request->bearerToken()), 401);
    $event = $request->input('event');   // e.g. employee.onboarded
    $data  = $request->input('data');    // the record
    // updateOrCreate on $request->input('reference') so a repeated delivery does not duplicate
    return response()->json(['ok' => true]);
});
```

Setup for this feature: run `database/sql/026_partner_push.sql` once (as root in MySQL Workbench).
