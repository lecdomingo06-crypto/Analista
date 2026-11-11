<dialog class="notifications-dialog" id="notifications-dialog" data-notifications-dialog
    data-state-url="{{ route('account.notifications.state') }}"
    data-read-all-url="{{ route('account.notifications.readall') }}"
    aria-labelledby="notifications-title" aria-modal="true">
    <div class="notifications-content">
        <header class="notifications-heading">
            <span class="notifications-heading-icon" aria-hidden="true"><x-icon name="bell"/></span>
            <div class="notifications-heading-copy">
                <span class="notifications-brand" aria-hidden="true"><x-icon name="mark"/>analista<span>.</span></span>
                <h2 id="notifications-title">Notifications</h2>
                <p>Your latest community updates.</p>
            </div>
            <button class="notifications-close" type="button" data-notifications-close aria-label="Close notifications" title="Close (Esc)"><span aria-hidden="true">×</span></button>
        </header>
        <div class="notifications-toolbar">
            <span data-notifications-unread aria-live="polite"></span>
            <button type="button" data-notifications-read-all hidden>Mark all as read</button>
            <button type="button" data-notifications-refresh>Refresh</button>
        </div>
        <div class="notifications-history" data-notifications-history tabindex="0" aria-label="Your notifications" aria-busy="false">
            <section aria-labelledby="notifications-updates-title">
                <h3 class="notifications-section-title" id="notifications-updates-title">Recent updates</h3>
                <div data-notifications-items></div>
                <p class="notifications-empty" data-notifications-empty hidden>You're all caught up. Approval decisions, review requests, and new recommendations will show up here.</p>
                <button class="notifications-more" type="button" data-notifications-more hidden>Load earlier updates</button>
            </section>
            <section aria-labelledby="notifications-conversations-title" data-notifications-conversations-section hidden>
                <h3 class="notifications-section-title" id="notifications-conversations-title">Review conversations</h3>
                <div data-notifications-conversations></div>
            </section>
        </div>
        <p class="notifications-status" data-notifications-status role="status" aria-live="polite"></p>
        <button class="notifications-retry" type="button" data-notifications-retry hidden>Try again</button>
        <footer class="notifications-footer"><a href="{{ route('account.dashboard', ['tab' => 'notifications']) }}">View all notifications <x-icon name="arrow-up-right"/></a></footer>
    </div>
    <template data-notifications-system-icon><x-icon name="bell"/></template>
</dialog>
