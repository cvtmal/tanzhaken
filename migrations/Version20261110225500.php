<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The nine tables of cycle #3. The DDL of each branch is Doctrine's own dump of the
 * mapping (`doctrine:schema:create --dump-sql` on SQLite and on MariaDB 10.11), so the
 * generated FK names (FK_…) and FK indexes (IDX_…) match the mapping and
 * `doctrine:schema:validate` stays green.
 */
final class Version20261110225500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the nine TanzHaken tables';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof SQLitePlatform) {
            $this->upSqlite();

            return;
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->upMysql();

            return;
        }

        $this->abortIf(true, 'SQLite or MariaDB/MySQL only');
    }

    public function down(Schema $schema): void
    {
        // Children before parents, so no foreign key blocks a drop.
        foreach (['check_in_window', 'attendance', 'credit_grant', 'lesson_session', 'enrollment', 'audit_event', 'course', 'student', 'teacher'] as $table) {
            $this->addSql(\sprintf('DROP TABLE %s', $table));
        }
    }

    /**
     * DDL auto-commits on MySQL-family platforms, so a transaction there would only pretend (P2).
     */
    public function isTransactional(): bool
    {
        return !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    private function upSqlite(): void
    {
        $this->addSql('CREATE TABLE teacher (id BLOB NOT NULL, name VARCHAR(120) NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, timezone VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uk_teacher_email ON teacher (email)');

        $this->addSql('CREATE TABLE audit_event (id BLOB NOT NULL, actor_type VARCHAR(16) NOT NULL, "action" VARCHAR(64) NOT NULL, entity_type VARCHAR(64) NOT NULL, entity_id BLOB NOT NULL, occurred_at DATETIME NOT NULL, details_json CLOB NOT NULL, teacher_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_3CE56E5041807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_audit_event_teacher_occurred ON audit_event (teacher_id, occurred_at)');
        $this->addSql('CREATE INDEX IDX_3CE56E5041807E1D ON audit_event (teacher_id)');

        $this->addSql('CREATE TABLE student (id BLOB NOT NULL, first_name VARCHAR(80) NOT NULL, last_name VARCHAR(80) NOT NULL, email VARCHAR(180) DEFAULT NULL, notes CLOB DEFAULT NULL, created_at DATETIME NOT NULL, archived_at DATETIME DEFAULT NULL, teacher_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_B723AF3341807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_student_teacher_archived ON student (teacher_id, archived_at)');
        $this->addSql('CREATE INDEX IDX_B723AF3341807E1D ON student (teacher_id)');

        $this->addSql('CREATE TABLE course (id BLOB NOT NULL, name VARCHAR(120) NOT NULL, description CLOB DEFAULT NULL, location VARCHAR(160) DEFAULT NULL, timezone VARCHAR(64) NOT NULL, weekday SMALLINT NOT NULL, local_start_time TIME NOT NULL, duration_minutes SMALLINT NOT NULL, starts_on DATE NOT NULL, ends_on DATE DEFAULT NULL, created_at DATETIME NOT NULL, archived_at DATETIME DEFAULT NULL, teacher_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_169E6FB941807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_course_teacher_archived ON course (teacher_id, archived_at)');
        $this->addSql('CREATE INDEX IDX_169E6FB941807E1D ON course (teacher_id)');

        $this->addSql('CREATE TABLE enrollment (id BLOB NOT NULL, public_checkin_label VARCHAR(40) DEFAULT NULL, joined_at DATETIME NOT NULL, left_at DATETIME DEFAULT NULL, course_id BLOB NOT NULL, student_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_DBDCD7E1591CC992 FOREIGN KEY (course_id) REFERENCES course (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_DBDCD7E1CB944F1A FOREIGN KEY (student_id) REFERENCES student (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_enrollment_student ON enrollment (student_id)');
        $this->addSql('CREATE UNIQUE INDEX uk_enrollment_course_student ON enrollment (course_id, student_id)');
        $this->addSql('CREATE INDEX IDX_DBDCD7E1591CC992 ON enrollment (course_id)');

        $this->addSql('CREATE TABLE lesson_session (id BLOB NOT NULL, starts_at_utc DATETIME NOT NULL, ends_at_utc DATETIME NOT NULL, status VARCHAR(16) NOT NULL, cancelled_at DATETIME DEFAULT NULL, course_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_25388773591CC992 FOREIGN KEY (course_id) REFERENCES course (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_lesson_session_starts ON lesson_session (starts_at_utc)');
        $this->addSql('CREATE UNIQUE INDEX uk_lesson_session_course_starts ON lesson_session (course_id, starts_at_utc)');
        $this->addSql('CREATE INDEX IDX_25388773591CC992 ON lesson_session (course_id)');

        $this->addSql('CREATE TABLE credit_grant (id BLOB NOT NULL, quantity INTEGER NOT NULL, granted_at DATETIME NOT NULL, note VARCHAR(255) DEFAULT NULL, voided_at DATETIME DEFAULT NULL, enrollment_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_916AE8E28F7DB25B FOREIGN KEY (enrollment_id) REFERENCES enrollment (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_credit_grant_enrollment ON credit_grant (enrollment_id)');

        $this->addSql('CREATE TABLE attendance (id BLOB NOT NULL, status VARCHAR(16) NOT NULL, source VARCHAR(16) NOT NULL, marked_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, lesson_session_id BLOB NOT NULL, student_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_6DE30D916C36A50E FOREIGN KEY (lesson_session_id) REFERENCES lesson_session (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6DE30D91CB944F1A FOREIGN KEY (student_id) REFERENCES student (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_attendance_student_status ON attendance (student_id, status)');
        $this->addSql('CREATE UNIQUE INDEX uk_attendance_lesson_student ON attendance (lesson_session_id, student_id)');
        $this->addSql('CREATE INDEX IDX_6DE30D916C36A50E ON attendance (lesson_session_id)');
        $this->addSql('CREATE INDEX IDX_6DE30D91CB944F1A ON attendance (student_id)');

        $this->addSql('CREATE TABLE check_in_window (id BLOB NOT NULL, token_hash CHAR(64) NOT NULL, opened_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, lesson_session_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_96F4E2386C36A50E FOREIGN KEY (lesson_session_id) REFERENCES lesson_session (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_check_in_window_lesson ON check_in_window (lesson_session_id)');
        $this->addSql('CREATE UNIQUE INDEX uk_check_in_window_token_hash ON check_in_window (token_hash)');
    }

    /**
     * MariaDB 10.11 DDL, dumped from the mapping without a server; first executed by #21.
     */
    private function upMysql(): void
    {
        $this->addSql('CREATE TABLE teacher (id BINARY(16) NOT NULL, name VARCHAR(120) NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, timezone VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uk_teacher_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE audit_event (id BINARY(16) NOT NULL, actor_type VARCHAR(16) NOT NULL, action VARCHAR(64) NOT NULL, entity_type VARCHAR(64) NOT NULL, entity_id BINARY(16) NOT NULL, occurred_at DATETIME NOT NULL, details_json JSON NOT NULL, teacher_id BINARY(16) NOT NULL, INDEX idx_audit_event_teacher_occurred (teacher_id, occurred_at), INDEX IDX_3CE56E5041807E1D (teacher_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE student (id BINARY(16) NOT NULL, first_name VARCHAR(80) NOT NULL, last_name VARCHAR(80) NOT NULL, email VARCHAR(180) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, archived_at DATETIME DEFAULT NULL, teacher_id BINARY(16) NOT NULL, INDEX idx_student_teacher_archived (teacher_id, archived_at), INDEX IDX_B723AF3341807E1D (teacher_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE course (id BINARY(16) NOT NULL, name VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, location VARCHAR(160) DEFAULT NULL, timezone VARCHAR(64) NOT NULL, weekday SMALLINT NOT NULL, local_start_time TIME NOT NULL, duration_minutes SMALLINT NOT NULL, starts_on DATE NOT NULL, ends_on DATE DEFAULT NULL, created_at DATETIME NOT NULL, archived_at DATETIME DEFAULT NULL, teacher_id BINARY(16) NOT NULL, INDEX idx_course_teacher_archived (teacher_id, archived_at), INDEX IDX_169E6FB941807E1D (teacher_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE enrollment (id BINARY(16) NOT NULL, public_checkin_label VARCHAR(40) DEFAULT NULL, joined_at DATETIME NOT NULL, left_at DATETIME DEFAULT NULL, course_id BINARY(16) NOT NULL, student_id BINARY(16) NOT NULL, INDEX idx_enrollment_student (student_id), UNIQUE INDEX uk_enrollment_course_student (course_id, student_id), INDEX IDX_DBDCD7E1591CC992 (course_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE lesson_session (id BINARY(16) NOT NULL, starts_at_utc DATETIME NOT NULL, ends_at_utc DATETIME NOT NULL, status VARCHAR(16) NOT NULL, cancelled_at DATETIME DEFAULT NULL, course_id BINARY(16) NOT NULL, INDEX idx_lesson_session_starts (starts_at_utc), UNIQUE INDEX uk_lesson_session_course_starts (course_id, starts_at_utc), INDEX IDX_25388773591CC992 (course_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE credit_grant (id BINARY(16) NOT NULL, quantity INT NOT NULL, granted_at DATETIME NOT NULL, note VARCHAR(255) DEFAULT NULL, voided_at DATETIME DEFAULT NULL, enrollment_id BINARY(16) NOT NULL, INDEX idx_credit_grant_enrollment (enrollment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE attendance (id BINARY(16) NOT NULL, status VARCHAR(16) NOT NULL, source VARCHAR(16) NOT NULL, marked_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, lesson_session_id BINARY(16) NOT NULL, student_id BINARY(16) NOT NULL, INDEX idx_attendance_student_status (student_id, status), UNIQUE INDEX uk_attendance_lesson_student (lesson_session_id, student_id), INDEX IDX_6DE30D916C36A50E (lesson_session_id), INDEX IDX_6DE30D91CB944F1A (student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE check_in_window (id BINARY(16) NOT NULL, token_hash CHAR(64) NOT NULL, opened_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, lesson_session_id BINARY(16) NOT NULL, INDEX idx_check_in_window_lesson (lesson_session_id), UNIQUE INDEX uk_check_in_window_token_hash (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE audit_event ADD CONSTRAINT FK_3CE56E5041807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE student ADD CONSTRAINT FK_B723AF3341807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE course ADD CONSTRAINT FK_169E6FB941807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE enrollment ADD CONSTRAINT FK_DBDCD7E1591CC992 FOREIGN KEY (course_id) REFERENCES course (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE enrollment ADD CONSTRAINT FK_DBDCD7E1CB944F1A FOREIGN KEY (student_id) REFERENCES student (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE lesson_session ADD CONSTRAINT FK_25388773591CC992 FOREIGN KEY (course_id) REFERENCES course (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE credit_grant ADD CONSTRAINT FK_916AE8E28F7DB25B FOREIGN KEY (enrollment_id) REFERENCES enrollment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE attendance ADD CONSTRAINT FK_6DE30D916C36A50E FOREIGN KEY (lesson_session_id) REFERENCES lesson_session (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE attendance ADD CONSTRAINT FK_6DE30D91CB944F1A FOREIGN KEY (student_id) REFERENCES student (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE check_in_window ADD CONSTRAINT FK_96F4E2386C36A50E FOREIGN KEY (lesson_session_id) REFERENCES lesson_session (id) ON DELETE CASCADE');
    }
}
