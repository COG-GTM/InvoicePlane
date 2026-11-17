-- Index ip_invoices.client_id on its own.
--
-- The existing composite `user_id` index starts with user_id, so lookups by
-- client alone (the client list's per-client balance subquery, the client
-- detail page) fall back to a full table scan of ip_invoices for every client.

ALTER TABLE `ip_invoices`
    ADD INDEX `idx_invoices_client_id` (`client_id`);
