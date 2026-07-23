-- ══════════════════════════════════════════════════════════════════════════
-- P3A Helpdesk — seed data (Phase 1). Idempotent-ish: run against a fresh schema.
-- Datetimes are UTC (UTC_TIMESTAMP()), per §7.
--
-- DEFAULT SEED PASSWORD for all four users: P3a-Seed-Change!2026
--   → bcrypt cost-12 hash below. This credential is PUBLIC (it is in the repo).
--   → The admin has must_change_pw=1; change it on first login and enrol TOTP (§19.9).
-- ══════════════════════════════════════════════════════════════════════════

-- ── users: 1 super admin (hidden owner), 1 admin, 2 agents, 1 customer ──
-- The super admin is the protected, hidden owner account: it never appears in the
-- Agents list and cannot be edited/deactivated/deleted through the app. Change its
-- password on first login (must_change_pw = 1). Seeded password = the shared seed one.
INSERT INTO users (public_id, name, email, password_hash, role, active, must_change_pw, created_at) VALUES
  ('SA-0001', 'System Super Admin', 'superadmin@p3a-support.com.ng',
    '$2y$12$8xBEdTyz2WvCEtUH6HV4MONhzj2yVdJ2GX02kPAnGK2eZPROI.3uu', 'super_admin', 1, 1, UTC_TIMESTAMP()),
  ('AD-0001', 'System Admin', 'admin@p3a-support.com.ng',
    '$2y$12$8xBEdTyz2WvCEtUH6HV4MONhzj2yVdJ2GX02kPAnGK2eZPROI.3uu', 'admin', 1, 1, UTC_TIMESTAMP()),
  ('AG-0001', 'Agent One', 'agent1@p3a-support.com.ng',
    '$2y$12$8xBEdTyz2WvCEtUH6HV4MONhzj2yVdJ2GX02kPAnGK2eZPROI.3uu', 'agent', 1, 1, UTC_TIMESTAMP()),
  ('AG-0002', 'Agent Two', 'agent2@p3a-support.com.ng',
    '$2y$12$8xBEdTyz2WvCEtUH6HV4MONhzj2yVdJ2GX02kPAnGK2eZPROI.3uu', 'agent', 1, 1, UTC_TIMESTAMP()),
  ('CU-0001', 'Demo Customer', 'customer@example.com',
    '$2y$12$8xBEdTyz2WvCEtUH6HV4MONhzj2yVdJ2GX02kPAnGK2eZPROI.3uu', 'customer', 1, 0, UTC_TIMESTAMP());

-- ── config allowlist (§3 admin) ──
INSERT INTO config (`key`, value) VALUES
  ('company_name',         'TicketFlow'),
  ('support_email',        'support@p3a-support.com.ng'),
  ('portal_title',         'TicketFlow'),
  ('portal_tagline',       'How can we help you today?'),
  ('brand_color',          '#4057F5'),
  ('ticket_prefix',        'TKT'),
  ('business_hours_start', '09:00'),
  ('business_hours_end',   '17:00'),
  ('business_days',        '1,2,3,4,5'),
-- ── SLA targets: 4 tiers × response/resolution minutes (§3, §9; validated positive, resolution >= response) ──
  ('sla_response_urgent',    '30'),
  ('sla_response_high',      '120'),
  ('sla_response_normal',    '480'),
  ('sla_response_low',       '1440'),
  ('sla_resolution_urgent',  '240'),
  ('sla_resolution_high',    '480'),
  ('sla_resolution_normal',  '1440'),
  ('sla_resolution_low',     '4320');

-- ── categories (two-level: parents first, then children referencing category_id) ──
INSERT INTO categories (category_id, name, description, color, active, parent_id, created_at) VALUES
  ('CAT-001', 'General',   'General enquiries',        '#4057F5', 1, NULL,      UTC_TIMESTAMP()),
  ('CAT-002', 'Billing',   'Invoices and payments',    '#12B76A', 1, NULL,      UTC_TIMESTAMP()),
  ('CAT-003', 'Technical', 'Technical support',        '#F79009', 1, NULL,      UTC_TIMESTAMP());
INSERT INTO categories (category_id, name, description, color, active, parent_id, created_at) VALUES
  ('CAT-004', 'Login Issues', 'Cannot sign in',        '#F04438', 1, 'CAT-003', UTC_TIMESTAMP()),
  ('CAT-005', 'Bug Report',   'Something is broken',    '#F04438', 1, 'CAT-003', UTC_TIMESTAMP());

-- ── products / projects (a default so the required form dropdown is never empty) ──
INSERT INTO products (product_id, name, active, created_at) VALUES
  ('PRD-0001', 'General', 1, UTC_TIMESTAMP());

-- ── canned responses (demo; {customerName}/{agentName}/{ticketId} substituted at use — §3) ──
INSERT INTO canned_responses (response_id, title, body, category, active, created_by, created_at) VALUES
  ('CAN-001', 'Acknowledgement',
    'Hi {customerName}, thanks for contacting us. Your ticket {ticketId} has been received and a member of our team will respond shortly.',
    'General', 1, 'admin@p3a-support.com.ng', UTC_TIMESTAMP()),
  ('CAN-002', 'Resolved',
    'Hi {customerName}, we believe ticket {ticketId} is now resolved. If anything is still not working, reply and it will reopen automatically. — {agentName}',
    'General', 1, 'admin@p3a-support.com.ng', UTC_TIMESTAMP());
