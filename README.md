# MediBook — Doctor Appointment & Healthcare Management System

MediBook is a web-based Doctor Appointment and Healthcare Management System built with **Core PHP and MongoDB**. It provides dedicated workflows for patients, doctors, clinics, and administrators to manage healthcare appointments and related operations.

The project is designed with a responsive user interface and a modular PHP architecture, making it suitable for local development, academic use, and further open-source development.

## Key Features

### Patient Management
- Patient registration and login
- Doctor search and filtering
- Doctor profile and clinic information
- Appointment booking
- Appointment history
- Appointment cancellation and rescheduling
- Waitlist management
- Notifications
- Queue and token tracking
- Favorite doctors
- Appointment reviews
- Patient profile management

### Doctor Management
- Doctor registration and login
- Administrator-based doctor verification
- Professional profile management
- Recurring schedule management
- Break and leave management
- Appointment management
- Patient check-in
- Queue and token management
- Appointment status updates

### Administrator Management
- Administrative dashboard
- User management
- Doctor verification
- Specialization management
- Clinic and branch management
- Doctor-to-clinic assignment
- Appointment monitoring
- Review management
- Analytics and reports
- Audit logs
- System settings

### Advanced Features
- Dynamic appointment slot availability
- AJAX-based live slot updates
- Appointment conflict prevention
- Queue and token management
- Waitlist notifications
- Email notifications using PHPMailer
- Role-based access control
- CSRF protection
- Password hashing and verification
- Server-side validation

## Technology Stack

| Component | Technology |
|---|---|
| Frontend | HTML5, CSS3, JavaScript, Bootstrap |
| Backend | Core PHP |
| Database | MongoDB |
| MongoDB Library | mongodb/mongodb |
| Email Service | PHPMailer |
| Local Server | Apache (XAMPP) |
| Dependency Management | Composer |
| Version Control | Git and GitHub |

## Project Requirements

Before installing MediBook, make sure you have:

- Windows, Linux, or macOS
- XAMPP or another Apache/PHP development environment
- A compatible PHP version
- MongoDB Community Server
- PHP MongoDB extension (`ext-mongodb`)
- Composer
- Git (recommended)

The PHP MongoDB extension and the Composer MongoDB library are separate requirements. Both must be installed and configured correctly.

## Installation Guide

### 1. Clone the Repository

Open your terminal and run:

```bash
git clone https://github.com/khalid67-ux/doctor_appointment_system.git
```

### 2. Move the Project into XAMPP

Place the project inside:

```text
C:\xampp\htdocs\doctor_appointment_system
```

### 3. Start XAMPP

Open the XAMPP Control Panel and start:

- Apache

MongoDB must also be running separately as a MongoDB Server service or process.

### 4. Install Composer Dependencies

Open a terminal inside the project root directory:

```bash
composer install
```

This installs the dependencies defined in `composer.json` and `composer.lock`.

Current dependencies:

- `mongodb/mongodb` — MongoDB PHP library
- `phpmailer/phpmailer` — Email functionality

### 5. Configure MongoDB

Start your MongoDB Community Server.

The current configuration file is:

```text
config/mongodb.php
```

The default local connection configuration is:

```php
$mongoClient = new MongoDB\Client(
    'mongodb://127.0.0.1:27017'
);

$mongoDb = $mongoClient->selectDatabase(
    'doctor_appointment_db'
);
```

Default local configuration:

| Setting | Value |
|---|---|
| Host | 127.0.0.1 |
| Port | 27017 |
| Database | doctor_appointment_db |

If your MongoDB server uses authentication or a different connection URI, update the configuration securely.

### 6. Configure PHP MongoDB Extension

Make sure the MongoDB PHP extension is installed and enabled in your PHP environment.

Verify it using:

```bash
php -m
```

Look for:

```text
mongodb
```

Also verify Composer dependencies:

```bash
composer show mongodb/mongodb
```

### 7. Configure Email

MediBook uses PHPMailer for email-related functionality.

The configuration file is:

```text
config/mail.php
```

Configure your SMTP credentials locally.

**Security:** Never publish real SMTP passwords, API keys, or other credentials in the public repository.

### 8. Run MediBook

Open your browser:

```text
http://localhost/doctor_appointment_system/
```

Make sure Apache and MongoDB are running before accessing the application.

## Database Configuration

MediBook uses MongoDB as its database system.

- Database name: `doctor_appointment_db`
- Connection: MongoDB PHP Library
- Local server: `127.0.0.1:27017`

The application selects its database through the MongoDB PHP client.

Database collections and initial data must be configured according to the application's actual initialization and data requirements.

No MySQL or phpMyAdmin setup is required.

## Security Considerations

MediBook includes security-related implementation features such as:

- Password hashing using PHP password functions
- Role-based access control
- CSRF protection
- Server-side validation
- Protected application workflows

For secure development and deployment:

- Never commit API keys or passwords.
- Do not expose real patient information.
- Use secure MongoDB authentication where appropriate.
- Keep dependencies updated.
- Use HTTPS in production.
- Apply appropriate access controls and backups.
- Review all configuration files before publishing.

## Project Structure

The repository contains separate application areas and configuration files, including:

```text
doctor_appointment_system/
│
├── assets/
├── config/
│   ├── mail.php
│   └── mongodb.php
│
├── doctor/
├── includes/
├── patient/
├── vendor/
├── composer.json
├── composer.lock
├── .htaccess
└── README.md
```

Additional files and modules may be present in the repository.

## Development Notes

MediBook is currently designed for local development using XAMPP and MongoDB.

Before using it in a production environment, additional testing, security hardening, deployment configuration, monitoring, automated backups, and operational improvements are recommended.

## Contributing

Contributions, suggestions, bug reports, and feature improvements are welcome.

If you want to contribute:

1. Fork the repository.
2. Create a feature branch.
3. Make your changes.
4. Test your changes locally.
5. Submit a Pull Request with a clear description.

Please avoid committing sensitive information or unrelated changes.

## Project Status

MediBook is an actively maintained development project. Features, documentation, and implementation details may evolve as development continues.

## License

This project is intended to be released as open-source software. The applicable license and its terms should be specified in the repository's `LICENSE` file before redistribution.

---

**Repository:** https://github.com/khalid67-ux/doctor_appointment_system

**Built with Core PHP and MongoDB.**
