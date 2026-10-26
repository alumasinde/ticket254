-- 0015_add_ticket_sla_fields.sql
-- Response tracking and reporting indexes. due_at already exists on tickets (0007).
-- Overdue is computed on read: due_at < now and the status is not terminal. It is never stored.

ALTER TABLE tickets
  ADD COLUMN first_response_at DATETIME NULL AFTER due_at;

ALTER TABLE tickets
  ADD KEY idx_tickets_due (tenant_id, due_at),
  ADD KEY idx_tickets_resolved (tenant_id, resolved_at),
  ADD KEY idx_tickets_priority_status (tenant_id, priority_id, status_id),
  ADD KEY idx_tickets_first_response (tenant_id, first_response_at);
