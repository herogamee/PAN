# PAN Shopee Connector v2.4.4

This complete-source package is based on the supplied PAN source and includes Shopee Connector v2.4.4.
Live `storage/config.php` and `storage/pan.sqlite` are intentionally excluded from this distributable package.
The connector does not advance a checkpoint from Shopee metadata-only responses containing only `next_offset` and `translation_status`; it retries the same offset and then safely falls back to status-list.
