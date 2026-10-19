-- Roll back 047: remove supplier discount and inbound shipping fields.

ALTER TABLE purchase_invoices
  DROP COLUMN shipping_cost,
  DROP COLUMN discount;
