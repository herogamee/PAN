## PAN PR — Change summary
Closes #...
Phase: #...
Baseline version / commit:
Target version (if runtime changed):

## Data integrity
- [ ] Preserves existing Shopee account scoping, cancellation, checkpoint and item-snapshot rules
- [ ] No global order identity regression or unintended multi-marketplace assumptions
- [ ] For schema changes: migration + rollback documented, tested on SQLite/MySQL as relevant
- [ ] No real credentials, `storage/config.php`, DB, OTP, cookies or profile data added

## Testing
- [ ] PHP lint for PHP changes
- [ ] Shopee Extension regression for connector changes
- [ ] Server Connector tests for server code changes
- [ ] CI link on the exact commit:
- [ ] Real Shopee/DB staging tested if required (**otherwise write NOT VERIFIED**):
- [ ] Relevant acceptance case IDs updated in `docs/ACCEPTANCE-MATRIX.md`

## Release and rollback
- [ ] Version files aligned if the change modifies shipped runtime behavior
- [ ] Exact-base patch and Complete-package parity checked when packaging
- [ ] Backup and rollback procedure documented
- [ ] ROADMAP and parent Issue updated based on evidence, not assumptions

See [Roadmap](../ROADMAP.md) · [Acceptance Matrix](../docs/ACCEPTANCE-MATRIX.md).
