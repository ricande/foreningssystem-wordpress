# Open product and architecture questions

These must be answered deliberately. Do not silently turn assumptions into implementation.

## Resolved by the owner on 2026-09-24

- A board assignment cannot exist without an active membership. See `DECISIONS.md`.
- The signed hard copy is the archival original when it differs from the finalized minutes.
- Who may finalize minutes is an association setting.
- Personal data and audit events use a retention setting. The default is 5 years.
- The license is GPL-2.0-or-later.

## Blocking decision, not yet made

Whether a membership number identifies the person across every period, or one membership period only, is not decided.

The current database key is `UNIQUE` on `membership_number`, and the ledger rejects a number that already exists on any period. That behaves as one number per period. Product language can also be read as one number per person. Do not change the key, reuse a number for a returning member, or merge people by number until the owner chooses.

A returning person can receive a new period with a new number. The earlier period stays.

## Identity and membership

1. Is the core model `Person -> Membership`?
2. Can a person have multiple membership periods?
3. Can a board member, auditor or election-committee member exist without being a member?
4. Which contact details belong to the person, and which may belong to a specific role?
5. How are deceased former members represented without corrupting history?
6. What exactly does "delete member" mean compared with terminate membership, anonymize and erase personal data?

## Association periods

7. Is membership year configurable and allowed to differ from calendar year?
8. How are board terms represented?
9. Can a term be date-based, meeting-based, year-based, or all three?

## Meetings

10. Which meeting statuses are truly needed?
11. Which actions are permitted in each state?
12. Is agenda numbering automatic, manual, or both?
13. How are late agenda items handled?
14. Are notes themselves revisioned?
15. How do formal decisions differ from action items?
16. Can decisions be corrected after minutes have been adjusted, and by what explicit process?
17. What is the legal/product meaning of “adjusted/finalized” in the generic product, given that association bylaws vary?

## Minutes and documents

18. How is a minutes document edited before finalization?
19. What metadata identifies a finalized revision?
20. Should a PDF checksum/hash be stored?
21. If a generated PDF is regenerated from identical content, should it be byte-identical or only content-equivalent?
22. Is the scanned signed copy considered the archival original, or merely an attachment?
23. What happens if the signed copy differs from the generated revision?
24. Which file types are allowed for signed copies?

## WordPress architecture

25. Which domain objects require dedicated tables?
26. Which objects, if any, benefit from CPT/revisions/public permalinks?
27. Which data belongs in options?
28. How should Media Library attachments be linked without leaking private documents?
29. Do member-only documents require protected delivery rather than public attachment URLs?
30. What admin UX architecture gives a modern experience without creating an unmaintainable SPA?

## Privacy

31. Retention rules per object?
32. Which data may be exported in WordPress privacy exporter?
33. Which data may be erased automatically, anonymized, or must be retained?
34. What audit events are necessary, and how long are they retained?

## Public site

35. Which blocks are MVP?
36. Which blocks are always public and which need authentication/association membership checks?
37. How should caching behave for protected/member-only output?
