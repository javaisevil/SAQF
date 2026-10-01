# AQMS

Academic Quality Management System for traceable course and program quality workflows.

## What is included

- Faculty course-specification wizard with CLO/PLO alignment, assessment evidence, Jahiziah skills, resources, approval data, and PDCA improvement history.
- HoD review with an explainable quality gate.
- QA exception queue for amber records.
- Automatic clearance of green records after HoD sign-off, with QA sampling retained for governance.
- Status history, comments, printable course evidence, program KPIs, and program-specification mapping.

The quality gate is deliberately deterministic. It reports the exact issue that needs attention instead of pretending to make an unexplained AI decision.

## Run without MAMP

### Docker

Install Docker Desktop, then run:

~~~bash
docker compose up --build
~~~

Open http://localhost:8080. The database is initialized from database/AQMS_db.sql the first time the database volume is created.

For a rehearsable hackathon demo, load the optional seed into a disposable database after it starts:

~~~bash
docker compose exec -T db sh -c 'mysql -uaqms -p"$MYSQL_PASSWORD" AQMS_db' < database/demo_seed.sql
~~~

It creates one green course and one amber exception course.

To start over with a fresh local database, remove only the named project volume:

~~~bash
docker compose down -v
~~~

### Existing university MySQL

1. Create the database and application user with the university's approved process.
2. Import database/AQMS_db.sql only into a new database. For an existing database already using this AQMS schema, run `mysql -u USER -p DATABASE < database/quality_gate_migration.sql`. It adds quality-gate metadata, assessment evidence fields, and the current PDCA fields; have the university DBA verify that the rest of the course-specification schema matches this project before connecting it.
3. Set AQMS_DB_HOST, AQMS_DB_PORT, AQMS_DB_NAME, AQMS_DB_USER, AQMS_DB_PASS, APP_ENV=production, and APP_DEBUG=false.
4. Point Apache or the Docker container at this folder and enable HTTPS.

For cPanel, use MySQL Database Wizard to create the database/user and phpMyAdmin to import the SQL. Put the credentials in the hosting environment if available, or create an ignored config.local.php from config.example.php.

## Configuration

The application reads environment variables first. For local PHP hosting where environment variables are not available, create config.local.php:

~~~php
<?php

return [
    'APP_ENV' => 'production',
    'APP_DEBUG' => false,
    'AQMS_DB_HOST' => 'localhost',
    'AQMS_DB_PORT' => '8889',
    'AQMS_DB_NAME' => 'AQMS_db',
    'AQMS_DB_USER' => 'aqms',
    'AQMS_DB_PASS' => 'replace-with-the-approved-password'
];
~~~

Never commit that file or real university credentials.

The built-in local defaults use `localhost:8889` for a typical MAMP MySQL setup. Set `AQMS_DB_PORT=3306` for a standard MySQL service. Docker supplies its own database host and port.

## Demo flow

1. On the disposable demo database, sign in as `faculty1`, `hod1`, or `qa1` with password `password`. The starter SQL includes demo accounts; replace or remove them before any internet-facing deployment.
2. Complete the wizard and add real rubric criteria and performance evidence.
3. Open the final review step to show the quality-gate result.
4. Submit to HoD.
5. Use one green course to demonstrate automatic clearance and one amber course to demonstrate the focused QA exception queue.
6. Open the printable full specification to show CLO/PLO traceability, Jahiziah tags, PDCA evidence, approval history, and the final record.
