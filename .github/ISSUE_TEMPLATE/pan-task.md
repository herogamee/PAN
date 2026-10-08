---
name: "PAN Phase Task"
about: "งานพัฒนา/แก้บั๊กของ PAN ที่ต้องเชื่อม Roadmap + Acceptance Case"
title: "[PAN][Phase X] "
assignees: ""
---

## Phase / Related issue
- Parent: #<phase>
- Case ID(s): PAN-Px-xx
- Target version (only if runtime changes):
- Baseline commit:

## Problem and observed behavior
เขียนสิ่งที่เกิดขึ้นจริง ไม่ใช้การคาดเดา ห้ามใส่ข้อมูลส่วนตัวหรือคุกกี้ใน Issue สาธารณะ

## Scope / Non-goals
- In scope:
- Out of scope / must preserve:

## Implementation checklist
- [ ] Confirm correct exact baseline and backups
- [ ] Implement minimal, backwards-compatible change
- [ ] Add regression for failure/negative case
- [ ] Update docs and risk/rollback notes

## Acceptance gates
- [ ] PHP lint / JS test / CI appropriate to changed files
- [ ] SQLite/MySQL database test when schema or import changes
- [ ] Authorized Shopee real-session check when endpoint behavior changes
- [ ] Account-isolation / checkpoint / cancellation / data preservation checked
- [ ] No secrets, production DB, buyer identities or session data in code/Issue

## Evidence
- Commit / PR:
- CI run URL:
- Staging case ID/result:
- Known blockers:
- Rollback instructions:

Follow [ROADMAP.md](../../ROADMAP.md) and [Acceptance Matrix](../../docs/ACCEPTANCE-MATRIX.md).
