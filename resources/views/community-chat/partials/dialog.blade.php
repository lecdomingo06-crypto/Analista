<dialog class="community-chat-dialog" id="community-chat-dialog" data-community-dialog aria-labelledby="community-chat-title" aria-modal="true">
    <button class="community-chat-close" type="button" data-community-close aria-label="Close community chat" title="Close (Esc)"><span aria-hidden="true">×</span></button>
    <div class="community-chat-content">
        <header class="community-chat-heading">
            <h2 id="community-chat-title">community chat</h2>
            <p data-community-room-status>A public space for the community.</p>
        </header>
        <div class="community-chat-history" data-community-messages role="log" aria-label="Community messages" aria-live="polite" aria-relevant="additions text" tabindex="0">
            <p class="community-chat-empty" data-community-empty>Loading the conversation…</p>
        </div>
        <div class="community-chat-feedback-row">
            <p class="community-chat-feedback" data-community-feedback role="status"></p>
            <button class="community-chat-retry" type="button" data-community-retry hidden>Try again ↻</button>
        </div>
        <p class="community-chat-as" data-community-as></p>
        <form class="community-chat-form community-identity-form" data-community-identity-form method="post" action="{{ route('community-chat.identity') }}" hidden>
            @csrf
            <label for="community-chat-name">what’s your name?</label>
            <div class="community-chat-compose-row">
                <input id="community-chat-name" name="name" placeholder="your name" maxlength="40" required autocomplete="nickname">
                <button type="submit">next <span aria-hidden="true">↵</span></button>
            </div>
        </form>
        <form class="community-chat-form" data-community-message-form method="post" action="{{ route('community-chat.messages.store') }}" hidden>
            @csrf
            <label class="sr-only" for="community-chat-body">Your community message</label>
            <div class="community-chat-compose-row">
                <textarea id="community-chat-body" name="body" placeholder="say something…" maxlength="500" rows="1" required></textarea>
                <button type="submit">send <span aria-hidden="true">↵</span></button>
            </div>
        </form>
    </div>
</dialog>
