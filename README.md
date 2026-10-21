# MediBook — Dynamic Doctor Appointment & Healthcare Management System

Academic PHP/MySQL implementation aligned with the project proposal. The system covers patient, doctor and administrator workflows, including doctor verification, search/filtering, dynamic availability, appointment booking, conflict prevention, cancellation/rescheduling, queue/token management, waitlist, notifications, reviews, favorites, analytics and audit logs.

The proposal specifies HTML5/CSS3/Bootstrap/JavaScript on the frontend, PHP on the server, MySQL as the relational database, Apache/XAMPP for local development, Git/GitHub for version control, and a responsive web interface. fileciteturn1file2L199-L224

## Local setup (XAMPP)

1. Install/start XAMPP.
2. Start **Apache** and **MySQL**.
3. Extract this folder into:
   `C:\xampp\htdocs\doctor-appointment-system\`
4. Open phpMyAdmin:
   `http://localhost/phpmyadmin`
5. Create/select database `doctor_appointment_db`.
6. Import `database/database.sql`.
   - The SQL is a clean academic setup script and recreates the project tables. Do not import it over a database containing data you need to keep.
7. Confirm `config/database.php` matches your local MySQL credentials. Default XAMPP is:
   - Host: `localhost`
   - User: `root`
   - Password: blank
   - Database: `doctor_appointment_db`
8. Open:
   `http://localhost/doctor-appointment-system/`

## Demo accounts

All demo accounts use password: **123456**

| Role | Email |
|---|---|
| Administrator | `admin@medibook.local` |
| Doctor | `doctor@medibook.local` |
| Patient | `patient@medibook.local` |

The demo doctor is already approved, assigned to a clinic branch, and has recurring working hours so the dynamic slot flow can be tested immediately.

## Main workflows

### Patient
- Register/login
- Search and filter verified doctors
- View doctor profile, clinics and ratings
- Check AJAX live slots
- Book an available slot
- View appointment history
- Cancel/reschedule according to configured rules
- Join/cancel waitlist
- View notifications
- Track queue/token
- Favorite doctors
- Review completed appointments
- Update profile

### Doctor
- Register/login after administrator verification
- Manage professional profile
- Manage recurring schedule
- Add breaks and leave dates
- View appointments
- Confirm requests
- Check-in patients and assign tokens
- Move queue through status flow
- Complete appointments

### Administrator
- Dashboard and operational metrics
- User management
- Doctor verification
- Specialization management
- Clinic/branch management
- Doctor-branch assignment
- Appointment monitoring
- Review monitoring
- Analytics/reports
- Audit logs
- System settings

## Dynamic/advanced implementation

- AJAX live slot availability
- Slot generation from recurring schedules, breaks, leave dates and existing bookings
- Transaction + row locking during booking to reduce double booking
- Live queue refresh
- Waitlist notification worker
- Reminder notification worker
- Role-specific access control
- CSRF protection for POST forms
- Password hashing with PHP `password_hash()` / `password_verify()`
- Prepared PDO statements
- Audit logging for important actions

## Reminder and waitlist workers

From the project root, run through the XAMPP PHP CLI if configured:

```bash
php api/send_reminders.php
php api/process_waitlist.php
```

For a real deployment, these should be scheduled with a cron/Task Scheduler job.

## Architecture

The implementation follows a three-layer web-application approach described by the proposal: presentation/UI, application logic and database persistence. fileciteturn1file2L225-L228

```text
Browser
  ↓
HTML + Bootstrap + CSS + JavaScript/AJAX
  ↓
PHP application
  ↓
PDO / MySQL
```

## Scope note

The project intentionally does not implement automated diagnosis, AI medical decision-making, emergency/ambulance dispatch, full EMR, pharmacy, insurance processing, real prescription generation, or video consultation, matching the proposal's out-of-scope items. fileciteturn2file1L96-L104

## Academic note

This is a local/academic project implementation. Before production deployment, add environment-based secrets, HTTPS, stronger operational logging, rate limiting, automated backups, scheduled workers, comprehensive automated tests and deployment hardening.
