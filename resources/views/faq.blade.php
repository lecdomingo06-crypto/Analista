@extends('layouts.app')
@section('title', 'Frequently asked questions')
@section('body-class', 'evaluators-page faq-page')
@push('styles')
<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/evaluators.css') }}?v={{ filemtime(public_path('assets/evaluators.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/faq.css') }}?v={{ filemtime(public_path('assets/faq.css')) }}">
@endpush
@section('content')
@include('evaluators.partials.sky')
<a class="evaluators-back page-back" href="{{ route('home') }}"><x-icon name="arrow-left"/>Back to home</a>
<header class="faq-header">
    <div class="faq-mascot" aria-hidden="true">@include('home.partials.community-mascot')</div>
    <h1>A little <em>clarity.</em></h1>
    <p>Questions about getting started, sharing your thesis, or giving feedback? Find your answers here.</p>
</header>

@php
    $faqGroups = [
        [
            'id' => 'getting-started',
            'title' => 'Getting started',
            'icon' => 'user',
            'questions' => [
                [
                    'id' => 'faq-choose-an-account',
                    'question' => 'Is Analista free, and which account should I choose?',
                    'answer' => 'Analista is free for students and volunteer IT professionals. Choose Student if you are working on a thesis or capstone, or Evaluator if you want to offer professional feedback. Evaluator accounts need admin approval before they can accept reviews.',
                ],
                [
                    'id' => 'faq-google-sign-in',
                    'question' => 'How does Continue with Google work?',
                    'answer' => 'New users choose Student or Evaluator and accept the terms after signing in with Google. If an Analista account already uses that email, sign in with its existing Analista password once to link Google. Your saved role, projects, and reviews stay with the same account.',
                ],
                [
                    'id' => 'faq-forgot-password',
                    'question' => 'What if I forget my password or signed up with Google?',
                    'answer' => 'Choose Forgot password on the login page to request a password reset link for your account email. If you joined with Google, you can keep using Google or choose Add a password in My profile to request an email link for email sign-in.',
                    'link' => ['route' => 'password.request', 'label' => 'Reset your password'],
                ],
                [
                    'id' => 'faq-update-profile',
                    'question' => 'Where can I update my account information?',
                    'answer' => 'Open My profile to change your photo, display name, city, or password. Evaluators manage professional details through Verification. Approved evaluators can also update review availability and capacity in My profile.',
                ],
            ],
        ],
        [
            'id' => 'evaluators',
            'title' => 'Evaluators',
            'icon' => 'shield-check',
            'questions' => [
                [
                    'id' => 'faq-evaluator-application',
                    'question' => 'What do I need to apply as an evaluator?',
                    'answer' => 'Complete your professional details, explain your background, and provide supporting documents, a portfolio, or a professional reference. Include a relevant work sample and a practice review. Government IDs are not required.',
                ],
                [
                    'id' => 'faq-verification-privacy',
                    'question' => 'Who can see my verification evidence?',
                    'answer' => 'Your verification application, supporting files, and professional reference details are available to you and authorized administrators. They do not appear on your public profile. Share evidence you have permission to use and remove unnecessary personal details.',
                ],
                [
                    'id' => 'faq-application-status',
                    'question' => 'How do I know whether my application is approved?',
                    'answer' => 'Check Verification for your application status and the admin decision note. Your profile stays hidden from the evaluator directory while approval is pending. If changes are requested or an application is rejected, review the note and submit an updated application when ready.',
                ],
                [
                    'id' => 'faq-evaluator-availability',
                    'question' => 'Can I edit my evaluator details or pause new requests?',
                    'answer' => 'Update professional details and evidence through Verification; submitting an updated application starts another admin review. Changing an approved evaluator’s display name also requires an updated application. Use My profile to pause new requests or change your capacity without resubmitting your professional evidence.',
                ],
            ],
        ],
        [
            'id' => 'projects-reviews',
            'title' => 'Projects & reviews',
            'icon' => 'folder',
            'questions' => [
                [
                    'id' => 'faq-drafts-and-submissions',
                    'question' => 'What is the difference between a saved draft and a submitted project?',
                    'answer' => 'A saved draft stays out of the community directory and cannot receive reviews. Submitting a valid project makes it ready for review automatically. Its summary appears in community projects only if you choose to share it. Admins can still moderate unsuitable content.',
                ],
                [
                    'id' => 'faq-thesis-pdf-privacy',
                    'question' => 'Does sharing my project summary make my thesis PDF public?',
                    'answer' => 'No. Your group and authorized admins can access thesis documents. By default, an assigned evaluator can access only the PDF version attached to an accepted or completed review. You may instead share all thesis PDF versions with approved, active evaluators while your summary is public and the project is ready for review or completed. Guests and unrelated students cannot access thesis PDFs.',
                ],
                [
                    'id' => 'faq-request-or-volunteer',
                    'question' => 'How do request-only and volunteer reviews differ?',
                    'answer' => 'With By request only, your group chooses an evaluator and sends a request for a specific PDF version. With Open to volunteer evaluators, approved evaluators with available capacity can start reviewing the latest PDF until your reviewer limit is reached. Volunteer reviews require a submitted project with a public summary.',
                    'link' => ['route' => 'evaluators.index', 'label' => 'Find an evaluator'],
                ],
                [
                    'id' => 'faq-upload-a-revision',
                    'question' => 'What happens when I upload a revised thesis PDF?',
                    'answer' => 'Use Upload a revision on your project page. The new PDF becomes another version; earlier versions remain available to people with permission. Existing reviews stay attached to their original PDF version.',
                ],
                [
                    'id' => 'faq-review-dates-and-approval',
                    'question' => 'Is a preferred completion date guaranteed, and does a review approve my thesis?',
                    'answer' => 'Your preferred date is a request. Evaluators confirm a date they can accommodate, and reviews are voluntary and free. Their recommendations help you improve your work; your institution’s adviser and panel remain responsible for academic assessment and approval.',
                ],
                [
                    'id' => 'faq-view-a-review',
                    'question' => 'Where can I see my evaluator’s review?',
                    'answer' => 'In My projects, choose View review on your project card, then select an evaluator to open their review and recommendations. Evaluators can follow their own requests and reviews through My reviews in the workspace menu.',
                ],
            ],
        ],
        [
            'id' => 'messages-privacy',
            'title' => 'Messages & privacy',
            'icon' => 'message-circle',
            'questions' => [
                [
                    'id' => 'faq-who-to-message',
                    'question' => 'Who can I message, and can we keep talking after a review ends?',
                    'answer' => 'Start a conversation from an approved, active evaluator’s profile. Existing conversations can continue after a review is declined or completed. Sending pauses if either account is suspended; an active participant can still read the existing conversation when the other account is suspended.',
                ],
                [
                    'id' => 'faq-private-messages',
                    'question' => 'Who can open my messages and attachments?',
                    'answer' => 'Only the conversation’s participants can open its messages and attachments through the app. Administrators do not gain access to someone else’s private conversation just because they are an admin.',
                ],
                [
                    'id' => 'faq-message-attachments',
                    'question' => 'Can I send pictures and files in Messages?',
                    'answer' => 'Use the attachment button to send photos, PDFs, Word documents, spreadsheets, presentations, text, CSV, or ZIP files, with or without a message. Each message supports up to 5 files, 10 MB per file, and 20 MB in total.',
                ],
                [
                    'id' => 'faq-report-a-concern',
                    'question' => 'How do I report a concern, and who sees my report?',
                    'answer' => 'Use Report on a project page or evaluator profile to flag spam, fake information, inappropriate content, or harassment. Reports go to a private admin queue. Your identity as the reporter stays private from other users. Admins review the content before taking action and may share the relevant correction or moderation reason with the responsible user.',
                ],
            ],
        ],
    ];
@endphp

<div class="faq-layout">
    <div class="faq-groups">
        @foreach($faqGroups as $group)
            <section class="faq-group" id="{{ $group['id'] }}" aria-labelledby="{{ $group['id'] }}-title">
                <div class="faq-group-heading">
                    <span class="faq-group-icon" aria-hidden="true"><x-icon :name="$group['icon']"/></span>
                    <h2 id="{{ $group['id'] }}-title">{{ $group['title'] }}</h2>
                </div>
                @foreach($group['questions'] as $question)
                    <details class="faq-question" id="{{ $question['id'] }}">
                        <summary>{{ $question['question'] }}<span class="faq-toggle" aria-hidden="true"></span></summary>
                        <div class="faq-answer">
                            <p>{{ $question['answer'] }}</p>
                            @isset($question['link'])
                                <a href="{{ route($question['link']['route']) }}">{{ $question['link']['label'] }}<x-icon name="arrow-up-right"/></a>
                            @endisset
                        </div>
                    </details>
                @endforeach
            </section>
        @endforeach
    </div>
</div>
<p class="faq-guidelines">For more on sharing work and protecting your information, read our <a href="{{ route('guidelines') }}">community guidelines and privacy information</a>.</p>
@endsection
@push('scripts')
<script src="{{ asset('assets/faq.js') }}?v={{ filemtime(public_path('assets/faq.js')) }}" defer></script>
@endpush
