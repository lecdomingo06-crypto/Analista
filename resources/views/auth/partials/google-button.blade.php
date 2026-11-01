@if(filled(config('services.google.client_id')) && filled(config('services.google.client_secret')) && filled(config('services.google.redirect')))
    @php
        $googleParameters = [];
        if ($googleRegistration ?? false) {
            $googleParameters['role'] = ($invitation ?? null) || request('role') === 'evaluator' ? 'evaluator' : 'student';
            if (is_string(request('invite')) && request('invite') !== '') {
                $googleParameters['invite'] = request('invite');
            }
        }
    @endphp
    <div class="registration-divider"><span>or</span></div>
    <a class="registration-google-button" href="{{ route('auth.google.redirect', $googleParameters) }}">
        <img src="{{ asset('assets/google-g.png') }}" width="20" height="20" alt="">
        <span>Continue with Google</span>
    </a>
@endif
