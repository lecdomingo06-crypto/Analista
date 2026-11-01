<section class="community-widget" data-community-chat
    data-state-url="{{ route('community-chat.state') }}"
    data-presence-url="{{ route('community-chat.presence') }}"
    data-identity-url="{{ route('community-chat.identity') }}"
    data-send-url="{{ route('community-chat.messages.store') }}"
    aria-label="Community">
    <div class="community-viewers" data-community-viewers aria-hidden="true"></div>
    <p class="community-viewing" data-community-viewing>Connecting to community…</p>
    <button class="community-chat-open" type="button" data-community-open aria-haspopup="dialog" aria-controls="community-chat-dialog" aria-expanded="false"><x-icon name="message-circle"/><span>community chat</span></button>
    <noscript><p class="community-chat-noscript">Enable JavaScript to join the chat.</p></noscript>
</section>
