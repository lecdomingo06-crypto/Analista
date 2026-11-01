# Analista

A free community platform connecting thesis students with volunteer IT evaluators. Built with Laravel 12, PHP 8.2+, SQLite (or MySQL), and server-rendered Blade. The interface follows the supplied monochrome reference, with self-hosted Geist Pixel, Geist, and Geist Mono fonts.

## Run locally

Dependencies and the local SQLite database have already been prepared in this workspace.

```powershell
composer run preview
```

Open **http://127.0.0.1:8000**. This command serves only `public/` and permits PDF uploads up to 20 MB.

For XAMPP, start Apache and open **http://localhost/Analista/public**. Apache must allow Laravel's `public/.htaccess` rewrite rules. Configure PHP's `upload_max_filesize=20M` and `post_max_size=22M` for uploads larger than the server's existing limit. The included `.user.ini` provides these settings on PHP-FPM hosts.

## Accounts and evaluator approval

Students and professionals register using their own names and email addresses. Evaluators go directly to Verification after registration. The proof form is available immediately: professional details, supporting evidence, a relevant work sample, and a short practice review are submitted together. Verification is the place to update professional details and request a new review.

Professional profile fields and the Verification page are exclusive to accounts registered as evaluators. My profile lets every role update their name, city, profile picture, and password; email stays read-only. Pictures accept JPEG, PNG, or WebP up to 2 MB and 4096 pixels per side, and appear beside the member's name throughout the community. Replacing or removing a picture deletes the previous owned photo. Password changes require the current password and a matching new password of at least 10 characters, and keep the current session signed in. Approved evaluators can also manage availability and review capacity there. Basic profile updates preserve professional credentials; changing an approved evaluator's display name requires another verification review. Admins review evaluator applications through Administration rather than the applicant's Verification page.

Applicants can provide 1–3 private PDF/JPG/PNG documents (up to 5 MB each), a professional portfolio with an ownership explanation, or a professional reference with permission. Every option still requires a work sample, a substantive practice review, and an accuracy/permission attestation. Government IDs are not required. Only the applicant and authorized admins can download evidence; files are stored outside `public/`.

Admins open an application's full verification page and record checks for identity/background, expertise/work sample, evidence authenticity, and feedback quality. Approval requires all four checks, a reason, a complete submission matching the current professional profile, and an active account. Admins can also request more information or reject with a reason. Every submission retains its submitted profile, evidence, and decision history, including reviewer and date. Reference details and portfolio links require a human review; the system does not automatically authenticate them or contact anyone.

Only approved, active evaluators appear in the directory or accept requests. Profiles with an approved verification matching their current professional details show “Admin-reviewed evaluator”; approval without reviewed evidence shows “Approved evaluator”. These badges describe community participation, not accreditation. Material profile changes or replacement evidence require a fresh submission and approval. Availability and capacity changes preserve approval.

The normal migrations and `DatabaseSeeder` never create evaluators, students, or thesis projects. If nobody has applied and been approved, the evaluator directory is empty. Admin invitations also require application review; they do not automatically grant approval.

For the three additional evaluator accounts requested by the project owner, run `php artisan db:seed --class=AdditionalEvaluatorSeeder`. This explicit local/testing seeder creates Adrian Mendoza, Camille Villareal, and Nathaniel Reyes as approved, available evaluators with `@analista.test` login identifiers. These identifiers are local account logins, not deliverable email addresses. Existing accounts from this seeder's earlier sample version are converted in place, preserving their IDs, profile photos, and related records. Conversion requires the original seed markers and aborts atomically on a conflicting destination account. Each created or converted account receives a unique random password displayed once in the command output; copy it then, because plaintext passwords are not saved. Re-running preserves regular accounts, passwords, and moderation decisions. Approval is recorded as the project owner's direct request; the seeder does not create verification evidence, ratings, employment claims, or years of experience. This seeder is separate from normal installation and refuses production environments.

Existing administrator and student accounts are preserved. The old fictional evaluator profiles were removed, and untouched example projects were retained as private drafts. Create a personal administrator account with the interactive command below if needed.

To install on a clean checkout:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
```

`DemoSeeder` is restricted to isolated automated tests and refuses local or production application databases. Never run `migrate:fresh` against data you want to keep.

## Features

- Student registration, sign-in/out, password recovery, and role-based workspaces.
- Professional applications with private evidence, work samples, practice feedback, admin checklists, recorded decisions, resubmissions, and shareable invitation links. All evaluators require admin approval; substantial profile changes return for review.
- Searchable approved evaluator directory, expertise filters, availability, workload limits, professional profiles, and ratings after completed reviews.
- Homepage evaluator cards follow the overlapping three-card reference. Click a side card, or use Enter/Space, to bring it forward. Up to three approved, available evaluators are chosen randomly once per session and stay in the same order on later visits; only profiles that become ineligible are replaced. Small pools show the available profiles without filler accounts.
- Group thesis projects with existing student accounts, private PDF uploads, immutable document versions, and student-controlled public summaries.
- My projects in the workspace sidebar, with search, status filters, and View/Edit actions for owned and group projects. Owners can delete a project by typing DELETE; this removes its files and related reviews while retaining private moderation reports. Group members can edit but cannot delete the owner's project.
- Admin project approval with reasons for requested changes or rejection. Public metadata edits return to moderation.
- Review requests, evaluator acceptance/decline, preferred/agreed dates, structured page/section feedback, questions, revision checklists, completion, and follow-up requests.
- Evaluators can download only versions attached to their accepted/completed assignments. Pending requests cannot access private PDFs.
- A single Notifications page for activity updates and review conversations, with unread counts and mark-as-read controls. Legacy Inbox links open Notifications; the sidebar provides the shared entry point.
- Private Messages with a searchable chat list, evaluator-card/profile message buttons, unread counts, sent/seen indicators, and automatic reply updates. Opening a new chat selects the evaluator without sending a message. Only the two participants can read it; suspended accounts cannot receive messages. Messages support plain text up to 5,000 characters and up to 5 attachments (10 MB each, 20 MB total). The paperclip adds JPEG/PNG/GIF/WebP images, PDF, Word, Excel, PowerPoint, text, CSV, and ZIP files, with optional message text. Users can preview and remove selected files before sending; failed sends retain the draft. Attachments are stored outside `public/` and require current participant access on every view or download. Images display in chat; documents download as files. Apply `php artisan migrate` when updating an existing installation to add the attachment metadata table.
- Free searchable library of 10 real YouTube thesis tutorials, list/grid views, click-to-play video pages, creator credits, and personal bookmarks. Existing written guides and their saved links remain accessible.
- Reports for projects/evaluators, private reporter identities, and admin resolution actions.
- Public FAQ at `/faq`, linked from the sidebar and footer, with expandable answers about accounts, evaluator verification, thesis projects, reviews, messages, and privacy. Expandable answers remain available without JavaScript.
- Admin management of applications, projects, users, reports, invitations, and tutorial publishing.
- Shared responsive sidebar with consistent width, typography, icons, and spacing across every page, plus light/dark theme preference, accessible forms, and locally hosted fonts. On short screens, only the navigation area scrolls while the logo and account controls stay in view. The homepage intro features a pixel cloud mascot with a four-second laptop-opening and typing animation. Its drifting blue sky extends as a separate background layer, preserving the hero and community spacing, then fades into the regular background above the video section; reduced-motion preferences show a static typing pose. The breadcrumb/search top bar has been removed.

## Administration and a real community

Create a new administrator interactively without a default password:

```powershell
php artisan analista:admin your-email@example.com --name="Your Name"
```

Run the normal `DatabaseSeeder` for the 10 curated video tutorials (YouTube links checked on 24 October 2026). Re-running it preserves admin edits and existing guides. Admins manage YouTube links, creator credits, notes, and publication under Administration → Tutorials. Evaluators join through the actual application and admin approval process. Configure `APP_URL`, `APP_DEBUG=false`, session settings, backups, and your web server's document root as `public/`.

Invitations generate a private, expiring link for the administrator to share; the application does not pretend to send invitation emails. Notifications are in-app. Password reset uses Laravel's mail transport: the default local `MAIL_MAILER=log` writes reset messages to `storage/logs/laravel.log`. Configure SMTP credentials in `.env` for delivered recovery emails. Mail credentials stay out of source control.

Project and evaluator approval establish eligibility for this platform. Academic assessment remains with institutional advisers and panels. See `/guidelines` for the in-product community/privacy explanation.

## Verification

```powershell
php artisan test
php artisan view:cache
```

Feature tests use isolated in-memory SQLite, independent of the application database. They cover evidence validation and private downloads, approval checks, stale submissions, verification history, access to exact thesis document versions, reports, authentication, profile changes, invitations, tutorials, bookmarks, page rendering, and safe removal of legacy examples.

Browser checks generate desktop/mobile screenshots and results locally in `docs/preview/`; these artifacts are not committed. `scripts/browser-check.cjs` checks public pages against the local server with a separately launched isolated headless Chrome debugging session on port 9227. Authenticated checks are optional: provide `ANALISTA_QA_STUDENT_EMAIL`/`ANALISTA_QA_STUDENT_PASSWORD` or `ANALISTA_QA_ADMIN_EMAIL`/`ANALISTA_QA_ADMIN_PASSWORD` for authorized test accounts. The script creates no accounts. It uses Node 24's built-in WebSocket client, without additional packages.

For a database left over from the original demo, `php artisan analista:retire-demo-records --dry-run --hide-sample-projects` previews matching legacy records. Applying it without `--dry-run` takes a private SQLite snapshot first, removes only identified sample evaluators without dependent data, archives those with related records, and preserves actual applications. The optional project flag makes only untouched, document-free example projects private drafts.

The frontend assets are served directly from `public/assets/`; no npm installation or Vite build is needed for this version.

`node scripts/verification-browser-check.cjs` exercises evaluator submission and admin approval using a separate temporary SQLite database, internally generated credentials, and owned preview/browser processes on ports 8002/9228. It refuses the application database, existing QA files, and occupied ports. The QA records never enter the website's application database. Screenshots and results are written to `docs/preview/`.

`node scripts/message-attachments-browser-check.cjs` checks message uploads, private downloads, previews, failed-send drafts, and mobile layout using two synthetic accounts in a separate temporary database. Uploads, sessions, caches, and the headless browser stay in its own temporary directory; external requests are blocked. It checks that ports 8065/9295 are free, cleans up its own data, and saves screenshots/results under `docs/preview/message-attachments-*`.

## Planning and font attribution

- [Project flowchart](docs/planning/analista-flowchart.md)
- [Flowchart image](docs/planning/analista-flowchart.png)
- [Geist font source](https://github.com/vercel/geist-font)
- [Bundled font license](public/assets/fonts/OFL.txt)
