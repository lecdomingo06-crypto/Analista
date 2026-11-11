# Analista presentation and demonstration guide

Suggested length: 15 minutes, followed by questions. This guide follows the current application behavior.

For the five-member graded video, use [the video presentation guide](video-presentation-guide.md), which assigns speaking parts and maps demonstrations to GUI, Algorithm, Visualization, and Database.

## The story to present

A student group needs practical feedback on a thesis project. They submit a PDF, choose an approved volunteer IT evaluator, and receive recommendations. The administrator reviews evaluator applications and manages the community.

Use the same example project throughout the demonstration. Suggested fictional title: **Campus Appointment and Queue Management System**.

## Prepare before the presentation

- Start the local server and confirm that the website opens. For the current XAMPP setup, use `http://localhost/Analista/public`.
- Prepare a student account, an approved evaluator account that accepts requests and has available capacity, and an administrator account.
- Keep the roles signed in through separate browser profiles or separate browsers. Tabs in the same browser profile share the same login session.
- Prepare a short sample thesis PDF and a revised version containing an obvious change. Use content suitable for an audience.
- Prepare one completed review as a backup and, if you want to demonstrate evaluator approval, a complete pending application with demonstration evidence.
- Rehearse the entire workflow once. Confirm that the evaluator can accept a new request and that your sample project is ready for review.
- Keep account passwords and `.env` out of the projected screen.

## Presentation order

| Step | Time | Screen or activity | Main point |
|---|---:|---|---|
| 1 | 1 minute | Opening introduction | Explain the problem and the system's purpose. |
| 2 | 1 minute | Homepage and public navigation | Introduce students, evaluators, and administrators. |
| 3 | 3 minutes | Student account | Submit a thesis PDF and request a review. |
| 4 | 3 minutes | Evaluator account | Accept the request, review the PDF, and send recommendations. |
| 5 | 2 minutes | Student account again | Show the delivered review, rating, and PDF version history. |
| 6 | 3 minutes | Administration | Show evaluator approval, moderation, and activity history. |
| 7 | 1 minute | Supporting features | Briefly show messages, attachments, tutorials, and FAQ. |
| 8 | 1 minute | Closing | Connect the demonstration to the system's benefits. |

## 1. Opening introduction

Suggested script:

> Good day. We are presenting Analista, a free web application that connects thesis students with volunteer IT evaluators. Students can submit their work, request practical feedback, and keep their documents and review results organized in one place. Administrators review evaluator applications and manage the community.

State three objectives:

1. Help students find evaluators whose expertise matches their project.
2. Organize thesis submissions, document versions, and review results.
3. Support communication and community management through defined account roles.

## 2. Homepage and roles

Open the homepage. Briefly point out **Find evaluators**, **Thesis projects**, and **Tutorials & tips**.

Explain the roles:

- **Student:** submits thesis projects, requests reviews, reads recommendations, and uploads revisions.
- **Evaluator:** submits a verification application, manages availability, and provides reviews after approval.
- **Administrator:** reviews evaluator applications, moderates projects and accounts, and monitors community activity.

Suggested transition:

> We will follow one student project from submission to a completed evaluator review.

## 3. Student demonstration

1. Switch to the student account and open **My projects**.
2. Choose **New project** and enter the sample title, description, category, and feedback needed.
3. Upload the sample PDF. Mention that an existing student can be added as a group member.
4. Select **By request only** for this demonstration and leave PDF access at its assigned-evaluator setting.
5. Explain the public-summary choice: sharing the summary controls the community listing; thesis PDF access has separate permissions.
6. Submit the project. Explain that a draft stays private and unavailable for reviews, while a valid submitted project becomes ready for review automatically.
7. Use **Find evaluators** to open an approved, available evaluator's profile. Point out expertise, availability, and ratings.
8. Open the project's review-request controls, choose that evaluator and PDF version, enter a short request and preferred date, and submit.

Suggested explanation:

> The student has now requested feedback on a specific PDF version. The evaluator will decide whether they can accept the request.

There are two review modes in the application. Keep the live demonstration on **By request only**. Briefly explain that **Open to volunteer evaluators** lets eligible evaluators volunteer for a submitted public project, subject to capacity and the project's reviewer limit.

## 4. Evaluator demonstration

1. Switch to the approved evaluator account.
2. Open **My reviews** and select the student's pending request.
3. Choose **Accept & start review**, with a proposed completion date if appropriate.
4. Open or download the PDF attached to this review.
5. Enter a useful written review summary. For example:

> The project addresses a clear campus need. Strengthen the methodology by explaining how participants will be selected, how waiting time will be measured, and which results will demonstrate improvement. Include a diagram showing the appointment and queue workflow.

6. Show the optional annotated PDF attachment if you prepared one.
7. Choose **Complete & send review**.

Suggested explanation:

> The evaluator's summary and optional annotated PDF are delivered to the thesis group through Messages and Notifications. The review remains linked to the document version that was evaluated.

For request-only projects, a completed review marks the project completed. Volunteer projects depend on their required reviewer count and remaining active reviews. Present the result produced by the chosen demonstration mode.

## 5. Return to the student

1. Open **Notifications** and show the completed-review update.
2. Open **My projects**, select **View review**, and choose the evaluator to read the result.
3. Show the summary and optional annotated PDF, then show the completed-review rating form.
4. Open the project and use **Upload a revision** with the revised sample PDF.
5. Show both PDF versions. Explain that the earlier review stays attached to its original PDF.

Suggested explanation:

> The group can act on the recommendations and retain its document history. It can also continue discussing the project with the evaluator through Messages.

Uploading a revision preserves version history. It does not automatically start another review or reopen a completed project.

## 6. Administrator demonstration

Switch to the administrator account and open **Administration**.

Show these sections in order:

1. **Overview:** community totals and activity.
2. **Evaluators:** open the prepared application, explain the submitted profile and evidence, and show the review checklist and decision controls. Approval requires a complete, current application, the required checks, and a decision note.
3. **Projects:** inspect a sample project and show request-changes, reject, and hide controls. Explain that moderation decisions require a reason. Use a separate practice project if demonstrating a moderation action live.
4. **Reviews:** show ongoing and completed reviews and their progress.
5. **History:** show the action, affected person or project, administrator, time, and decision note.
6. Briefly point out **Reports**, **Users**, **Tutorials**, and **File access**.

Suggested explanation:

> The administrator manages participation and community concerns. Evaluators provide professional feedback, while the institution's adviser and panel handle academic assessment and approval.

## 7. Supporting features

Keep this section brief:

- **Messages:** show the student's conversation with the evaluator and the attachment button. Conversation participants can access their private messages and attachments.
- **Tutorials & tips:** open a learning resource and show the save/bookmark action.
- **FAQ:** open one answer that helps a new user understand the workflow.
- **Community guidelines:** point out where members can read participation expectations.

## 8. Closing statement

Suggested script:

> Analista brings the student, evaluator, and administrator workflows together. Students can find relevant help, evaluators can deliver practical recommendations, and administrators can manage the community. Thesis documents, review results, and communication remain organized throughout the process.

Invite questions.

## If asked about the database

Use [the beginner database diagram](database-reviewer/01-start-here.png) to explain this connection:

```text
Student account -> Thesis project -> PDF version -> Review request <- Evaluator account
```

Explain the main tables in plain language:

- `users` stores student, evaluator, and administrator accounts.
- `projects` stores thesis details and the owner account's ID.
- `document_versions` stores PDF version information and its project ID.
- `review_requests` connects the project, exact PDF version, and evaluator. It also records the review status and final summary.
- `feedback` stores structured feedback entries linked to review requests.

Names are stored in `users`, and thesis titles are stored in `projects`. Linked IDs let the application display those details together.

For the current local setup, describe the technology as **Laravel/PHP, Blade templates, CSS, JavaScript, and MariaDB/MySQL through XAMPP**. Use the full table diagrams for detailed questions after the main demonstration.

## Suggested division for five presenters

- Presenter 1: introduction, purpose, GUI, and roles.
- Presenter 2: student submission and review-request decision rules.
- Presenter 3: evaluator acceptance, review delivery, and the student's rating.
- Presenter 4: rating/progress visualizations and administration.
- Presenter 5: database relationships, joined query, and closing.

Keep the same example project and explain each handover so the audience can follow the story.
