# Analista project flowchart

Planning reference for a free, community-wide thesis feedback and learning platform.

## Thesis submission, review, and revisions

```mermaid
flowchart TD
    S([Student registers or signs in]) --> P[Create thesis project and add group members]
    P --> U[Upload a private PDF and describe the feedback needed]
    U --> A{Admin project review}
    A -->|Needs changes| C[Read the reason, edit, and resubmit]
    C --> A
    A -->|Rejected| X[Student receives the reason]
    A -->|Approved for review| B[Choose an approved, available evaluator]
    B --> R[Send review request with a preferred completion date]
    R --> D{Evaluator accepts?}
    D -->|Declines| B
    D -->|Accepts| V[Review the exact PDF version attached to the request]
    V --> F[Submit section or page recommendations and notify students]
    F --> Q[Students read feedback and ask clarification questions]
    Q --> Z{Upload a revision?}
    Z -->|No| H[Keep the document and feedback history]
    Z -->|Yes| N[Upload a new document version]
    N --> O{Request a follow-up review?}
    O -->|No| H
    O -->|Yes, subject to availability| B
```

Project approval means eligibility for the platform's review process. Academic approval remains with advisers and panels. Before approval, projects stay private and cannot request evaluator reviews. Public project summaries require student approval. Full thesis files and feedback are restricted to authorized project members, assigned evaluators, and administrators.

## Evaluator application and approval

```mermaid
flowchart TD
    P([Professional applies]) --> F[Complete evaluator application and profile]
    I([Admin invitation]) --> F
    F --> Q[Application pending admin review]
    Q --> A{Admin decision}
    A -->|Approve| D[Approved evaluator appears in the directory]
    A -->|Reject| R[Applicant receives the reason]
    D --> C[Manage availability and maximum concurrent reviews]
    C --> W[Accept or decline requests for approved projects]
```

Profile fields: display name, headline, professional role, years of experience, city, expertise categories, skills, accepting-requests setting, review capacity, online/on-site preference, LinkedIn or portfolio link, and biography. Applications and invitations both require admin approval.

## Reports and moderation

```mermaid
flowchart TD
    U([Signed-in member]) --> R[Report a project or evaluator]
    R --> E[Choose a reason and optionally add an explanation]
    E --> P[Private admin queue: Pending]
    P --> V[Admin reviews the report: Under review]
    V --> A[Admin decides: dismiss, request corrections, hide content, or suspend account]
    A --> F([Report resolved])
```

Report reasons include spam, fake information, inappropriate content, harassment, and other. Reporter identities remain private from other users. A report leads to an admin decision after review.

## Tutorials and administration

```mermaid
flowchart LR
    V([Visitor or registered member]) --> T[Read free tutorials and tips]
    A[Admin] --> M[Manage and publish tutorials and categories]
    M --> T
```

Tutorials are accessible without submitting a thesis project. Admins also manage accounts, evaluator invitations, project moderation, assignments, stalled requests, and reports. Reviews are voluntary; students propose completion dates and evaluators confirm their availability.

## Visual reference

Use the latest monochrome UI reference: white backgrounds, black and gray accents, thin borders, restrained rounding, left sidebar navigation, and subtle dotted patterns. Use Geist Pixel for prominent headings, Geist for body text and forms, and Geist Mono for compact navigation and labels. Earlier Panelista references guide the functional pages and forms.
