@extends('layouts.app')
@section('title','Community & privacy')
@section('crumb','community guidelines')
@section('body-class','guidelines-page')
@push('styles')
<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/guidelines.css') }}?v={{ filemtime(public_path('assets/guidelines.css')) }}">
@endpush
@section('content')
@include('guidelines.partials.sky')
<a class="guidelines-back page-back" href="{{ route('home') }}"><x-icon name="arrow-left"/>Back to home</a>
<header class="guidelines-header">
    <div class="guidelines-mascot">@include('home.partials.community-mascot')</div>
    <h1>Good work. <em>Good company.</em></h1>
    <p>Analista is a free space for students and volunteer IT professionals to learn from one another.</p>
</header>
<section class="guidelines-grid" aria-label="Community guidelines and privacy">
    <article class="guidelines-card">
    <div class="guidelines-card-heading"><span class="guidelines-card-icon" aria-hidden="true"><x-icon name="upload"/></span><h2>Share work you’re responsible for.</h2></div>
    <p>Submit genuine thesis or capstone projects, involve your group members, and upload documents you’re authorized to share. Explain what you need reviewed and remove confidential participant information before uploading.</p>
    </article>
    <article class="guidelines-card">
    <div class="guidelines-card-heading"><span class="guidelines-card-icon" aria-hidden="true"><x-icon name="heart"/></span><h2>Give guidance with care.</h2></div>
    <p>Offer specific, constructive recommendations. Ask questions with respect and acknowledge the time volunteered by your evaluator. Students remain responsible for their writing, research, and revisions.</p>
    </article>
    <article class="guidelines-card">
    <div class="guidelines-card-heading"><span class="guidelines-card-icon" aria-hidden="true"><x-icon name="shield-check"/></span><h2>Know what approval means.</h2></div>
    <p>Evaluator applicants provide professional evidence, a relevant work sample, and a practice review. An admin checks their background, expertise, supporting evidence, and feedback quality before approving their participation. “Admin-reviewed evaluator” describes this review of submitted information. It is not a professional license or academic endorsement.</p>
    <p>New projects are ready for review as soon as they are submitted. Project teams choose whether to publish their summary, and admins can moderate unsuitable content. Evaluator recommendations help you improve your work. Your institution’s advisers and panels remain responsible for academic assessment and approval.</p>
    </article>
    <article class="guidelines-card">
    <div class="guidelines-card-heading"><span class="guidelines-card-icon" aria-hidden="true"><x-icon name="lock"/></span><h2>Professional evidence stays private.</h2></div>
    <p>Only the applicant and authorized administrators can access verification submissions, professional references, and supporting files. These do not appear on public profiles. Upload only evidence you are permitted to share, obtain a reference’s permission, and redact unnecessary personal details. Government IDs are not required.</p>
    <p>Applications retain a private submission and decision history. Material changes to professional details or replacement evidence need another review. A reference email or portfolio link must be checked by an admin; submitting one does not automatically establish its authenticity.</p>
    </article>
    <article class="guidelines-card">
    <div class="guidelines-card-heading"><span class="guidelines-card-icon" aria-hidden="true"><x-icon name="clock"/></span><h2>Keep expectations realistic.</h2></div>
    <p>All reviews are voluntary and free. Students can suggest completion dates; evaluators accept requests based on their availability and capacity. You can request another evaluator when a request is declined.</p>
    </article>
    <article class="guidelines-card">
    <div class="guidelines-card-heading"><span class="guidelines-card-icon" aria-hidden="true"><x-icon name="folder"/></span><h2>Your documents stay within your project.</h2></div>
    <p>Project owners, their registered group members, and administrators can access thesis documents. By default, an evaluator receives access only to the PDF version attached to an accepted or completed review. Teams can invite evaluators or open a public project to volunteers with a reviewer limit. Starting a volunteer review assigns the current PDF version to that evaluator. A project team can also share its thesis PDF versions with all approved, active evaluators while its summary is shared and the project is ready for review or completed. Guests and unrelated students cannot access thesis PDFs. Review feedback, summaries, and annotated PDFs stay private to the authorized team, admins, and assigned evaluator.</p>
    </article>
    <article class="guidelines-card">
    <div class="guidelines-card-heading"><span class="guidelines-card-icon" aria-hidden="true"><x-icon name="flag"/></span><h2>Help keep the community safe.</h2></div>
    <p>Report spam, fake information, inappropriate content, or harassment using the Report button on project pages and evaluator profiles. Reports enter a private admin queue. Your identity is kept private from other users. Admins review reports before requesting changes, hiding content, or suspending accounts.</p>
    </article>
    <article class="guidelines-card">
    <div class="guidelines-card-heading"><span class="guidelines-card-icon" aria-hidden="true"><x-icon name="user"/></span><h2>Manage your information.</h2></div>
    <p>You can update your profile, control evaluator availability, and choose whether your project summary is public. Contact the site administrator through your project’s review and moderation process if you need help with account or document access. Administrators can see the information needed to manage the platform.</p>
    </article>
</section>
@endsection
