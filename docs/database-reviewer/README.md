# Analista database reviewer

Created November 8, 2026 from the local `analista` database schema. The diagrams include all **29 tables, 275 columns and 35 enforced foreign keys**. No live account records, credentials, messages or private document contents are included.

## Single PDF reviewer

[Download the 13-page PDF](Analista-database-reviewer.pdf). It includes six beginner study pages covering terminology, relationships, SELECT and JOIN queries, practice questions with answers, and the table catalog, followed by all seven diagrams. Diagram pages use larger sizes to preserve readable column labels when zoomed.

## Study order

1. **01-start-here.png** — the student / project / PDF / evaluator / review flow, plus a fictional example of columns and rows.
2. **02-accounts-and-projects.png** — full columns for accounts, student ownership, group membership and PDF versions.
3. **03-reviews-and-verification.png** — review assignments, feedback, ratings, evaluator applications, evidence and invitations.
4. **04-messages-and-learning.png** — private conversations, attachments, community chat, tutorials and saved tutorials.
5. **05-notifications-and-administration.png** — notifications, moderation reports, administrator decisions and file access history.
6. **06-laravel-system-tables.png** — sessions, password reset tokens, caching, jobs and migration history.
7. **07-all-tables-map.png** — one large, zoomable poster containing every table, column and enforced foreign-key target.

The separate sheets are easier to read on a phone or to print. The complete map is 4110 pixels wide; open it at full size and zoom in.

## How to read the diagrams

- **Table:** a named collection such as `users` or `projects`.
- **Column:** one named field such as `name`, `role` or `project_id`.
- **Row:** one saved record. The rows shown on sheet 01 are fictional examples.
- **PK:** primary key, uniquely identifying a row. It is often `id`, but can also be a string, such as a session ID or email.
- **FK →:** database-enforced foreign key. The arrow points from a column in the child table to the referenced key in its parent table. For example, `projects.user_id → users.id`.
- **REF ⇢:** reference interpreted by application code, rather than an enforced database foreign key. This distinction matters for session, report and audit fields.
- **UQ:** a unique column/index. Composite unique indexes are available in the schema source but are not individually labeled on these beginner sheets.
- **\*:** the column allows NULL, meaning a value may be absent. This does not mean that every user-facing form makes the field optional.
- **SQL TYPE:** the type read from MariaDB. Integer display widths are shortened; text length limits are retained. Some application JSON values are stored as LONGTEXT in MariaDB, and boolean fields commonly use TINYINT(1).

## Main connections to remember

```text
projects.user_id                   -> users.id              (student owner)
project_user.user_id               -> users.id              (additional member)
project_user.project_id            -> projects.id
document_versions.project_id      -> projects.id
document_versions.user_id         -> users.id              (uploader)
review_requests.project_id        -> projects.id
review_requests.evaluator_id      -> users.id              (evaluator)
review_requests.requested_by      -> users.id              (requester)
review_requests.document_version_id -> document_versions.id (exact PDF record)
feedback.review_request_id        -> review_requests.id
feedback.user_id                  -> users.id              (feedback author)
```

Students, evaluators and administrators share `users`. Their `role` distinguishes account types. Names remain in `users`; thesis titles remain in `projects`. A JOIN reads linked records together. A foreign key checks that a referenced parent exists; role restrictions and whether records belong to the same project are also checked by application logic.

`document_versions.id` is the PDF record ID. `document_versions.version` is the visible version number. PDFs, photos and attachments live in private file storage; the database records their paths and metadata.

## Practice: display the linked names

Select `analista` in phpMyAdmin, open the SQL tab, and run this read-only query:

```sql
SELECT
    evaluator.name AS evaluator_name,
    projects.title AS thesis_title,
    student.name AS student_name,
    review_requests.status
FROM review_requests
JOIN users AS evaluator
    ON review_requests.evaluator_id = evaluator.id
JOIN projects
    ON review_requests.project_id = projects.id
JOIN users AS student
    ON projects.user_id = student.id;
```

Here `student` and `evaluator` are aliases: two temporary names for the same `users` table. `student` identifies the project owner in this query; additional project members are in `project_user`.

These files document the schema as it existed on the creation date. Later schema changes can make a saved reviewer outdated. SVG files and `schema.json` are retained beside the PNGs as editable/schema-only sources.
