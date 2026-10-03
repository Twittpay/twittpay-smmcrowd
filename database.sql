-- ===========================================================================
--  TWITTPAY - SMMCrowd
--  Adds the gateway row. Import this once, in phpMyAdmin, into your panel's
--  database. Nothing else in the database is touched.
--
--  The Brand Key and Endpoint URL are left empty on purpose, and the row is added
--  switched off - fill the two fields in from the admin area, then enable it.
--
--  If phpMyAdmin complains about a duplicate key, id 176 is already used on your
--  panel. Change BOTH `id` and `code` below to the same free number (for example
--  177) and import again - the panel looks the gateway up by `code`, so the two
--  must stay equal.
-- ===========================================================================

INSERT INTO `gateways`
(`id`, `form_id`, `code`, `name`, `alias`, `status`, `gateway_parameters`, `supported_currencies`, `crypto`, `extra`, `description`, `created_at`, `updated_at`)
VALUES
('176', '0', '176', 'Bkash/Nagad/Rocket/Upay', 'TwittPay', '0',
'{\"api_key\":{\"title\":\"Brand Key\",\"global\":true,\"value\":\"\"},\"api_url\":{\"title\":\"Endpoint URL\",\"global\":true,\"value\":\"\"}}',
'{\"BDT\":\"BDT\"}',
'0', NULL, NULL, '2026-01-01 00:00:00', '2026-01-01 00:00:00');
