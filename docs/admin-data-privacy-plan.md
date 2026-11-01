# Administration Improvements: Data Privacy Plan

Date: October 29, 2026

Status: All planned features implemented on October 30, 2026.

## Goal

Improve administration using minimal information while keeping private materials behind existing permissions. Preserve existing user, evaluator, and project records.

## Proposed features

| Feature | Privacy approach |
| --- | --- |
| Search and status filters | Search only records the administrator is already authorized to access. |
| Aggregate dashboard | Show totals and trends without displaying thesis contents, verification evidence, or private feedback. |
| Review monitoring | Show the project, assigned evaluator, review status, and agreed completion date. Keep detailed feedback on the protected review page. |
| Admin action history | Record the administrator, action, affected record, date, and reason. Avoid copying private document contents or credentials into logs. |
| Sensitive-file access history | Record who viewed or downloaded private evidence and thesis PDFs. Restrict this history to authorized administrators. |

## Recommended first phase

- [x] Add search and status filters within existing administration sections.
- [x] Improve the overview with aggregate counts and trends.
- [x] Add restricted admin action history.

### First-phase behavior

- Evaluators, projects, reports, users, tutorials, and invitations have search and status filters. Users also have a role filter. Pagination retains the selected filters and section.
- Search uses authorized list metadata, such as names and titles; selectors filter statuses. It does not search thesis contents, verification evidence, review feedback, report descriptions, or tutorial bodies.
- Project moderation defaults to submitted projects. Saved drafts remain available through the status filter; no project records were removed or changed.
- Community reports use a compact queue of 10 reports per page, showing open reports (pending and under review) by default. All statuses and resolved reports remain available through the filter. Each report opens an admin-only details page with the reporter, full description, inspection link, and existing decision controls; returning to the queue retains its search, status, and page. The queue loads only report metadata and reported-content titles/names, without descriptions or decision notes. Existing moderation permissions, actions, notifications, and audit history remain in place.
- The overview shows aggregate totals and a rolling seven-day activity comparison with the preceding seven days. It does not list individual projects, applicants, or reports.
- The community overview includes project-status bars and a review-progress doughnut chart, with visible labels and counts. They use only grouped status totals, handle empty datasets, and run only on the overview. Account and guide totals retain their existing definitions. The charts use local HTML, CSS, and SVG without sending data to an external chart service.
- The History tab is restricted to active administrators. It records new successful administration actions, neutral record IDs, permitted status changes, available decision reasons, and the administrator and timestamp.
- History uses a compact table with the action, administrator, date, and linked report context. Report actions resolve the current reported project/evaluator and reporter names from existing records, with a View report link and expandable recorded changes/decision note. Deleted records have neutral fallbacks. Only metadata is loaded for the current page; report descriptions, emails, and private document contents are excluded. Search also supports the linked reported title, evaluator, reporter, and report reason. Names and titles are not copied into audit storage, and viewing or searching history does not change its immutable entries.
- Existing records are not backfilled into history. History begins with actions performed after this feature is enabled.
- History has no edit or delete controls. Action records and their corresponding changes are saved in the same transaction.
- Audit storage excludes invitation emails/tokens and document/tutorial contents. Decision reasons are escaped for display and obvious credential patterns, links, and email addresses are redacted in the audit copy.

## Later additions

- [x] Add review monitoring using review status and agreed completion dates.
- [x] Add restricted access history for sensitive files.

### Review monitoring behavior

- Administration → Reviews shows a compact, read-only table with project title, assigned evaluator, review status, assigned PDF version, agreed completion date, and a link to the existing protected review page.
- Search is limited to project titles, evaluator names, and exact review IDs. Status filters cover ongoing, overdue, completed, pending, and declined reviews; pagination retains these filters.
- Ongoing totals include overdue reviews. A review is overdue only while its stored status is accepted and its agreed date is earlier than today in the application's Asia/Manila timezone. Due-today, undated, completed, pending, and declined reviews are not marked overdue.
- Overdue indicators and whole days overdue are calculated for display. They do not change the stored review status, assignments, document access, completion rules, or project data.
- Private review messages, feedback, review summaries, annotated PDF paths, and evidence are not loaded or searched by the monitoring list. View review opens the existing authorized detail page.
- This addition has no reminders, automatic penalties, cancellation, or reassignment. It requires no database migration or data reset.

### Sensitive-file access history behavior

- Administration → File access is restricted to active administrators and provides a read-only history of authorized download requests for thesis PDFs, annotated review PDFs, and evaluator evidence.
- Each entry stores only the requesting account ID and role at the time, file type and neutral record ID, applicable project/application ID, applicable thesis version number, and timestamp. Account names are resolved from the existing account record; deleted accounts appear as Former account while their role and neutral file references remain.
- Thesis and review PDF entries display the current project title from the existing project record. Titles are not copied into audit storage; deleted projects use the neutral file label instead.
- Search is limited to account names and exact entry, file, project, or application IDs. File-type and role filters are available; pagination preserves these filters.
- No filenames, storage paths, file contents, project titles, email addresses, IP addresses, user agents, session details, credentials, or invitation tokens are copied into this history.
- An entry is created only after authorization, file existence checks, and download-response preparation succeed for a GET request. Denied requests, missing files, HEAD requests, and ordinary page views do not create download entries.
- These entries show that a download request was authorized. They do not prove that transfer completed or that the recipient read the file; a later interrupted transfer can still have an entry. The application has no inline PDF preview, and opening external portfolio links is not tracked by this feature.
- Existing thesis-version, annotated-review, and evidence permissions remain unchanged. If recording an authorized download fails, the file response is not returned.
- The history has no file-download shortcuts or edit/delete controls. It begins with new requests after activation; past access is not backfilled.

## Privacy and access requirements

- Preserve existing permissions for thesis PDFs, evaluator evidence, private feedback, and annotated review PDFs.
- Keep administrator inspection of other teams' project content read-only, with existing moderation controls available.
- Keep project editing and revision uploads with authorized project owners and group members.
- Keep automatic project approval and existing review workflows intact.
- Store only the information needed for each administrative feature. Keep credentials, invitation tokens, and document contents out of audit logs.
- Match the existing compact interface and light/night themes.

## Implementation boundary

The first phase, review monitoring, and sensitive-file access history have been implemented. Additive migrations create separate administration and file-access history tables without changing existing user, evaluator, project, document, or review records. Review monitoring adds read-only queries and display logic. No database reset or seeding is required.
