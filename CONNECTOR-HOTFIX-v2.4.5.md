# PAN Shopee Connector v2.4.5

Fixes Shopee `get_order_list` terminal metadata responses such as `data.next_offset=-1` with `translation_status` but no order array.

- A verified `next_offset=-1` ends only the current status type and advances to the next status type.
- A metadata-only response with a non-terminal next offset retries the same offset and never advances the checkpoint without orders.
- Response diagnostics now include both `data_next_offset` and `new_data_next_offset`.
- Extension version identity is synchronized across manifest, background, popup, and Copy Debug.
- Regression suite: 20/20 PASS.
