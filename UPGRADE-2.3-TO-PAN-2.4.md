# Upgrade: ITOOM Commerce Hub 2.3.0 → PAN 2.5.0

PAN 2.5.0 เป็นการเปลี่ยน product identity โดยคง schema และ compatibility ของข้อมูลเดิม

ก่อนอัปเกรดให้ backup `storage/config.php` และฐานข้อมูลเดิมเสมอ

- Primary URL ใหม่: `pan.itoom.work`
- New SQLite default: `pan.sqlite`
- New MySQL default: `itoom_pan`
- New API header: `X-PAN-Key`
- Old SQLite names และ old API headers ยังรองรับ

ถ้า production เดิมทำงานอยู่ที่ `commerce.itoom.work` ให้ deploy PAN ที่ `pan.itoom.work` ก่อน ทดสอบครบ แล้วค่อยเปลี่ยน `commerce.itoom.work` เป็น 301 redirect
