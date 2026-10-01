-- Makes the audit log append-only at database level (run by a DBA if the installer
-- could not create triggers, e.g. MySQL with binary logging and no SUPER privilege).
DELIMITER $$
DROP TRIGGER IF EXISTS audit_log_no_update$$
CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only';
END$$
DROP TRIGGER IF EXISTS audit_log_no_delete$$
CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only';
END$$
DELIMITER ;
