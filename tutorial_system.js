/**
 * QuillTalk Tutorial System V2
 * Enhanced with better positioning, keyboard navigation, and interactive elements
 */

const TUTORIAL_STEPS = [
    {
        title: "Welcome to QuillTalk!",
        description: "Let's take a quick tour of all the amazing features. You can skip this anytime or go through each feature step by step. Use arrow keys to navigate!",
        target: null,
        position: "center"
    },
    {
        title: "Contacts Sidebar",
        description: "This is where all your conversations live. Click on any contact to open their chat. Active chats show a blue highlight.",
        target: ".sidebar",
        position: "right",
        highlight: ".contact"
    },
    {
        title: "Search Contacts",
        description: "Use this search bar to quickly find any conversation. Just start typing a name!",
        target: ".sidebar-contact-search",
        position: "right"
    },
    {
        title: "New Conversation",
        description: "Click the 'New' button to start a friend request, create a group chat, or start a group call. Go ahead, click it!",
        target: ".sidebar-new-trigger",
        position: "right",
        interactive: true,
        waitForClick: true,
        showArrow: true
    },
    {
        title: "Friend Request Option",
        description: "This option lets you search for users and send them friend requests. Try clicking it!",
        target: "[data-open-friend-request-modal]",
        position: "right",
        interactive: true,
        requiresDropdown: ".sidebar-new-trigger"
    },
    {
        title: "Group Chat Option",
        description: "Use this option to create a new group chat with multiple people.",
        target: "[data-open-group-chat-modal]",
        position: "right",
        interactive: true,
        requiresDropdown: ".sidebar-new-trigger"
    },
    {
        title: "Group Call Option",
        description: "This lets you start a group call straight from the New menu.",
        target: "#openGroupCallModalDesktop, #openGroupCallModalMobile",
        position: "right",
        interactive: true,
        requiresDropdown: ".sidebar-new-trigger"
    },
    {
        title: "Chat Header",
        description: "See who you're chatting with, their online status, and quick access to call and info buttons.",
        target: ".chat-header",
        position: "bottom",
        requiresSelectedChat: true
    },
    {
        title: "Voice & Video Calls",
        description: "Click the phone icon to start a voice call, or the video icon for a video call. Works for both 1-on-1 and group chats!",
        target: "#start-call-btn, #start-call-btn-mobile",
        position: "bottom",
        requiresSelectedChat: true
    },
    {
        title: "Contact Info",
        description: "Click the info icon to see contact details, set nicknames, block users, or manage group settings.",
        target: "#right-sidebar-toggle-btn, #contact-info-btn-mobile",
        position: "bottom",
        requiresSelectedChat: true
    },
    {
        title: "Message Input",
        description: "Type your messages here. Press Enter to send, or Shift+Enter for a new line.",
        target: "#messageInput",
        position: "top",
        requiresSelectedChat: true
    },
    {
        title: "Mentions & Commands",
        description: "Type @ to mention someone or / to use commands. Mentions and commands appear in bold!",
        target: "#messageInput",
        position: "top",
        requiresSelectedChat: true
    },
    {
        title: "Send Images & Files",
        description: "Click the image icon to send photos, videos, or any file. You can also drag and drop files directly into the chat!",
        target: "#attachmentBtn",
        position: "top",
        requiresSelectedChat: true
    },
    {
        title: "Voice Messages",
        description: "Hold the microphone button to record a voice message. Release to send, or slide to cancel.",
        target: "#voiceRecordBtn",
        position: "top",
        requiresSelectedChat: true
    },
    {
        title: "Emojis",
        description: "Click the emoji button to add emojis to your message. Your recently used emojis appear first!",
        target: "#emojiBtn",
        position: "top",
        requiresSelectedChat: true
    },
    {
        title: "More Actions (+)",
        description: "Click the + button for more options: send GIFs, create polls, or schedule messages. Click it now!",
        target: "#chatActionsBtn",
        position: "top",
        interactive: true,
        waitForClick: true,
        showArrow: true,
        requiresSelectedChat: true
    },
    {
        title: "Send GIFs",
        description: "Search and send animated GIFs from Tenor. Perfect for expressing emotions!",
        target: "[data-action='gif']",
        position: "top",
        interactive: true,
        requiresDropdown: "#chatActionsBtn",
        requiresSelectedChat: true
    },
    {
        title: "Create Polls",
        description: "Create polls with multiple options. Great for making group decisions! Set a duration and let people vote.",
        target: "[data-action='poll']",
        position: "top",
        interactive: true,
        requiresDropdown: "#chatActionsBtn",
        requiresSelectedChat: true
    },
    {
        title: "Schedule Messages",
        description: "Schedule messages to be sent at a specific time. Perfect for reminders or sending messages when you're busy!",
        target: "[data-action='schedule']",
        position: "top",
        interactive: true,
        requiresDropdown: "#chatActionsBtn",
        requiresSelectedChat: true
    },
    {
        title: "Message Actions",
        description: "Click the three dots on any message to reply, forward, edit, delete, or react with emojis.",
        target: ".message-actions-btn",
        position: "left",
        highlight: ".message",
        requiresSelectedChat: true
    },
    {
        title: "Reply to Messages",
        description: "Replying creates a thread and shows which message you're responding to. Great for keeping conversations organized!",
        target: null,
        position: "center"
    },
    {
        title: "Forward Messages",
        description: "Forward messages to other contacts or groups. Select multiple recipients at once!",
        target: null,
        position: "center"
    },
    {
        title: "Edit Messages",
        description: "Made a typo? Edit your sent messages within a short time window. An 'edited' label will appear.",
        target: null,
        position: "center"
    },
    {
        title: "Delete Messages",
        description: "Delete messages for yourself only, or for everyone in the chat. Choose wisely!",
        target: null,
        position: "center"
    },
    {
        title: "React with Emojis",
        description: "React to messages with emojis! Click on a reaction to see who reacted. You can add multiple reactions.",
        target: null,
        position: "center"
    },
    {
        title: "Group Chats",
        description: "Create group chats with multiple people. Set a group name, icon, and description.",
        target: null,
        position: "center"
    },
    {
        title: "Group Settings",
        description: "Manage group permissions: who can send messages, add members, or make calls. Promote members to admin!",
        target: null,
        position: "center"
    },
    {
        title: "Add Group Members",
        description: "Add more people to your group chat anytime. The more the merrier!",
        target: null,
        position: "center"
    },
    {
        title: "Group Calls",
        description: "Start voice or video calls with multiple people. Everyone in the group can join!",
        target: null,
        position: "center"
    },
    {
        title: "Typing Indicators",
        description: "See when someone is typing in real-time. The three dots animation shows they're composing a message.",
        target: ".typing-row",
        position: "bottom",
        requiresSelectedChat: true
    },
    {
        title: "Online Status",
        description: "Green dot = online, gray dot = offline. See who's available to chat right now!",
        target: "#chatHeaderStatusDot",
        position: "bottom",
        requiresSelectedChat: true
    },
    {
        title: "Settings Menu",
        description: "Click your profile picture to open the account dropdown.",
        target: "[data-dropdown-toggle='profileDropdown'], [data-dropdown-toggle='mobileProfileDropdown']",
        position: "left",
        interactive: true,
        waitForClick: true,
        showArrow: true
    },
    {
        title: "Account Settings",
        description: "Choose Settings here to open the full settings screen.",
        target: "#accountBtn",
        position: "left",
        interactive: true,
        waitForClick: true,
        requiresDropdown: "[data-dropdown-toggle='profileDropdown'], [data-dropdown-toggle='mobileProfileDropdown']"
    },
    {
        title: "Appearance Settings",
        description: "Customize QuillTalk! Choose between light and dark mode, and pick your favorite accent color.",
        target: "#accountMenu .settings-nav-item[data-settings-target='appearance']",
        position: "right",
        requiresAccountMenu: true
    },
    {
        title: "Notification Settings",
        description: "Control how you get notified. Enable/disable sounds, desktop notifications, and more.",
        target: "#accountMenu .settings-nav-item[data-settings-target='notifications']",
        position: "right",
        requiresAccountMenu: true
    },
    {
        title: "Block Users",
        description: "Block users to prevent them from messaging or calling you. You can unblock them anytime from their profile.",
        target: null,
        position: "center"
    },
    {
        title: "Set Nicknames",
        description: "Give your friends custom nicknames that only you can see. Makes it easier to identify people!",
        target: null,
        position: "center"
    },
    {
        title: "Voice Message Transcription",
        description: "Can't listen to a voice message? Transcribe it to text! Just click the transcribe button on any voice message.",
        target: null,
        position: "center"
    },
    {
        title: "Search in Chat",
        description: "Use the search button in the chat header to find specific messages in your conversation history.",
        target: "#chatSearchBtn",
        position: "bottom",
        requiresSelectedChat: true
    },
    {
        title: "Message Timestamps",
        description: "Hover over any message to see exactly when it was sent. Edited messages show an 'edited' label.",
        target: null,
        position: "center"
    },
    {
        title: "Read Receipts",
        description: "See when your messages have been read. Two checkmarks mean delivered, blue checkmarks mean read!",
        target: null,
        position: "center"
    },
    {
        title: "That's Everything!",
        description: "You're all set! Explore QuillTalk and discover even more features as you chat. Have fun connecting with friends!",
        target: null,
        position: "center"
    }
];

const TUTORIAL_PROMPT_DELAY_MS = 2000;
const TUTORIAL_REMINDER_DELAY_MS = 60 * 60 * 1000;

class TutorialSystem {
    constructor() {
        this.currentStep = 0;
        this.isActive = false;
        this.overlay = null;
        this.tooltip = null;
        this.arrow = null;
        this.prompt = null;
        this.keyboardHandler = null;
        this.positionHandler = null;
        this.decoratedElements = [];
        this.stepInteractionCleanup = null;
        this.renderToken = 0;
        this.pendingPromptTimeoutId = 0;
        this.reminderTimeoutId = 0;
        this.statusTickerId = 0;
        this.hasCompletedTutorial = typeof tutorialCompleted !== 'undefined' ? tutorialCompleted === 1 : false;
        this.hasSkippedTutorial = typeof tutorialSkipped !== 'undefined' ? tutorialSkipped === 1 : false;
        this.reminderStorageKey = this.getReminderStorageKey();
        this.reminderAt = this.loadReminderTimestamp();
    }

    checkTutorialStatus() {
        return this.hasCompletedTutorial || this.hasSkippedTutorial;
    }

    shouldShowTutorial() {
        return !this.checkTutorialStatus() && !this.hasActiveReminder();
    }

    getReminderStorageKey() {
        const userKey = typeof currentUserId !== 'undefined' && currentUserId
            ? String(currentUserId)
            : 'guest';
        return `quilltalk.tutorial.reminder.v1.${userKey}`;
    }

    loadReminderTimestamp() {
        try {
            const raw = window.localStorage.getItem(this.reminderStorageKey);
            const parsed = Number(raw || 0);
            return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
        } catch (error) {
            return 0;
        }
    }

    persistReminderTimestamp(timestamp = 0) {
        const safeTimestamp = Number.isFinite(timestamp) && timestamp > 0 ? Math.floor(timestamp) : 0;
        this.reminderAt = safeTimestamp;

        try {
            if (safeTimestamp > 0) {
                window.localStorage.setItem(this.reminderStorageKey, String(safeTimestamp));
            } else {
                window.localStorage.removeItem(this.reminderStorageKey);
            }
        } catch (error) {}
    }

    hasActiveReminder() {
        return this.getReminderRemainingMs() > 0;
    }

    getReminderRemainingMs() {
        return this.reminderAt > 0 ? Math.max(0, this.reminderAt - Date.now()) : 0;
    }

    clearPendingPromptTimeout() {
        if (this.pendingPromptTimeoutId) {
            window.clearTimeout(this.pendingPromptTimeoutId);
            this.pendingPromptTimeoutId = 0;
        }
    }

    clearReminderTimeout() {
        if (this.reminderTimeoutId) {
            window.clearTimeout(this.reminderTimeoutId);
            this.reminderTimeoutId = 0;
        }
    }

    startStatusTicker() {
        if (this.statusTickerId) {
            return;
        }

        this.statusTickerId = window.setInterval(() => {
            if (!this.hasActiveReminder()) {
                this.stopStatusTicker();
            }
            this.updateTutorialStatusUi();
        }, 1000);
    }

    stopStatusTicker() {
        if (this.statusTickerId) {
            window.clearInterval(this.statusTickerId);
            this.statusTickerId = 0;
        }
    }

    clearReminder({ emit = true } = {}) {
        this.clearReminderTimeout();
        this.stopStatusTicker();
        this.persistReminderTimestamp(0);
        if (emit) {
            this.updateTutorialStatusUi();
        }
    }

    clearPrompt() {
        if (this.prompt && typeof this.prompt.remove === 'function') {
            this.prompt.remove();
        }
        this.prompt = null;
    }

    scheduleTutorialPrompt(delayMs = TUTORIAL_PROMPT_DELAY_MS) {
        this.clearPendingPromptTimeout();

        if (this.checkTutorialStatus() || this.isActive || this.prompt || this.hasActiveReminder()) {
            return;
        }

        this.pendingPromptTimeoutId = window.setTimeout(() => {
            this.pendingPromptTimeoutId = 0;
            this.showTutorialPrompt();
        }, Math.max(0, delayMs));
    }

    scheduleReminderPrompt() {
        this.clearReminderTimeout();

        if (this.checkTutorialStatus()) {
            this.clearReminder();
            return;
        }

        const remainingMs = this.getReminderRemainingMs();
        if (remainingMs <= 0) {
            this.clearReminder({ emit: false });
            this.updateTutorialStatusUi();
            this.showTutorialPrompt({ force: true });
            return;
        }

        this.startStatusTicker();
        this.updateTutorialStatusUi();

        this.reminderTimeoutId = window.setTimeout(() => {
            this.reminderTimeoutId = 0;
            this.clearReminder({ emit: false });
            this.updateTutorialStatusUi();
            this.showTutorialPrompt({ force: true });
        }, remainingMs);
    }

    formatReminderRemaining(remainingMs) {
        const totalSeconds = Math.max(0, Math.ceil(remainingMs / 1000));
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;

        if (hours > 0) {
            return `${hours}h ${String(minutes).padStart(2, '0')}m ${String(seconds).padStart(2, '0')}s`;
        }

        return `${minutes}m ${String(seconds).padStart(2, '0')}s`;
    }

    getTutorialStatusSnapshot() {
        if (this.hasCompletedTutorial) {
            return {
                key: 'completed',
                iconClass: 'fa-solid fa-check',
                label: 'Completed',
                detail: 'You already finished the guided tour.'
            };
        }

        if (this.hasSkippedTutorial) {
            return {
                key: 'skipped',
                iconClass: 'fa-solid fa-xmark',
                label: 'Skipped',
                detail: 'You chose to skip the guided tour.'
            };
        }

        const remainingMs = this.getReminderRemainingMs();
        if (remainingMs > 0) {
            return {
                key: 'delayed',
                iconClass: 'fa-regular fa-clock',
                label: 'Delayed',
                detail: `Reminding you in ${this.formatReminderRemaining(remainingMs)}`
            };
        }

        return {
            key: 'not_started',
            iconClass: 'fa-regular fa-circle',
            label: 'Not Started',
            detail: 'Start the guided tour whenever you want a walkthrough.'
        };
    }

    updateTutorialStatusUi() {
        const statusEl = document.getElementById('tutorialSettingsStatus');
        const iconEl = document.getElementById('tutorialSettingsStatusIcon');
        const labelEl = document.getElementById('tutorialSettingsStatusLabel');
        const detailEl = document.getElementById('tutorialSettingsStatusDetail');
        const snapshot = this.getTutorialStatusSnapshot();

        if (statusEl) {
            statusEl.dataset.state = snapshot.key;
        }

        if (iconEl) {
            iconEl.innerHTML = `<i class="${snapshot.iconClass}" aria-hidden="true"></i>`;
        }

        if (labelEl) {
            labelEl.textContent = snapshot.label;
        }

        if (detailEl) {
            detailEl.textContent = snapshot.detail;
        }

        return snapshot;
    }

    wait(ms) {
        return new Promise(resolve => {
            window.setTimeout(resolve, ms);
        });
    }

    isAccountMenuOpen() {
        const accountMenu = document.getElementById('accountMenu');
        if (!accountMenu) {
            return false;
        }

        return document.body.classList.contains('account-menu-open')
            || accountMenu.style.display === 'block'
            || window.getComputedStyle(accountMenu).display !== 'none';
    }

    isElementVisible(element) {
        if (!element || !document.body.contains(element) || element.hidden) {
            return false;
        }

        const style = window.getComputedStyle(element);
        if (style.display === 'none' || style.visibility === 'hidden') {
            return false;
        }

        const rect = element.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0;
    }

    isElementUsable(element) {
        if (!this.isElementVisible(element)) {
            return false;
        }

        if (element.disabled || element.getAttribute('aria-disabled') === 'true') {
            return false;
        }

        if (element.classList.contains('icon-disabled')) {
            return false;
        }

        return true;
    }

    findVisibleElement(selector, { allowDisabled = false } = {}) {
        if (!selector) {
            return null;
        }

        const matches = Array.from(document.querySelectorAll(selector));
        if (!matches.length) {
            return null;
        }

        const visibleMatches = matches.filter(element => this.isElementVisible(element));
        if (!visibleMatches.length) {
            return allowDisabled ? matches[0] : null;
        }

        if (allowDisabled) {
            return visibleMatches[0];
        }

        return visibleMatches.find(element => this.isElementUsable(element)) || visibleMatches[0];
    }

    getAssociatedMenu(trigger) {
        if (!trigger) {
            return null;
        }

        if (trigger.id === 'chatActionsBtn') {
            return document.getElementById('chatActionsDropdown');
        }

        const menuId = trigger.getAttribute('data-dropdown-toggle');
        return menuId ? document.getElementById(menuId) : null;
    }

    isTriggerOpen(trigger) {
        const menu = this.getAssociatedMenu(trigger);
        if (menu && menu.classList.contains('show')) {
            return true;
        }

        return !!trigger && trigger.getAttribute('aria-expanded') === 'true';
    }

    async ensureDropdownOpen(triggerSelector) {
        const trigger = this.findVisibleElement(triggerSelector, { allowDisabled: true });
        if (!trigger) {
            return { trigger: null, menu: null };
        }

        if (!this.isTriggerOpen(trigger)) {
            trigger.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
            await this.wait(180);
        }

        return {
            trigger,
            menu: this.getAssociatedMenu(trigger)
        };
    }

    closeTransientUi() {
        document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
            menu.classList.remove('show');
        });

        document.querySelectorAll('[data-dropdown-toggle]').forEach(toggle => {
            toggle.setAttribute('aria-expanded', 'false');
        });

        const chatActionsDropdown = document.getElementById('chatActionsDropdown');
        if (chatActionsDropdown) {
            chatActionsDropdown.classList.remove('show');
            chatActionsDropdown.setAttribute('aria-hidden', 'true');
        }

        const chatActionsBtn = document.getElementById('chatActionsBtn');
        if (chatActionsBtn) {
            chatActionsBtn.setAttribute('aria-expanded', 'false');
        }
    }

    async ensureSelectedConversation() {
        if (this.findVisibleElement('.contact.active')) {
            return true;
        }

        const firstContact = this.findVisibleElement('#contactsList .contact, .contact');
        if (!firstContact) {
            return false;
        }

        firstContact.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        await this.wait(260);
        return true;
    }

    async ensureAccountMenuOpen(panelKey = 'profile') {
        if (this.isAccountMenuOpen()) {
            return true;
        }

        if (typeof window.openAccountMenu === 'function') {
            window.openAccountMenu(panelKey);
            await this.wait(180);
            return this.isAccountMenuOpen();
        }

        return false;
    }

    async ensureStepPrerequisites(step) {
        if (!step) {
            return;
        }

        if (step.requiresSelectedChat) {
            await this.ensureSelectedConversation();
        }

        if (step.requiresAccountMenu) {
            await this.ensureAccountMenuOpen(step.accountMenuPanel || 'profile');
        }
    }

    async saveTutorialStatus(completed, skipped) {
        try {
            const response = await fetch('update_tutorial_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    token: token,
                    completed: completed ? 1 : 0,
                    skipped: skipped ? 1 : 0
                })
            });
            
            const data = await response.json();
            if (data.success) {
                this.hasCompletedTutorial = completed;
                this.hasSkippedTutorial = skipped;
                this.updateTutorialStatusUi();
            } else {
                console.error('Failed to save tutorial status:', data.error);
            }
        } catch (e) {
            console.error('Failed to save tutorial status:', e);
        }
    }

    init() {
        if (this.checkTutorialStatus()) {
            this.clearReminder({ emit: false });
            this.updateTutorialStatusUi();
            return;
        }

        this.updateTutorialStatusUi();

        if (this.hasActiveReminder()) {
            this.scheduleReminderPrompt();
            return;
        }

        if (this.reminderAt > 0) {
            this.clearReminder({ emit: false });
            this.updateTutorialStatusUi();
            this.showTutorialPrompt({ force: true });
            return;
        }

        this.scheduleTutorialPrompt();
    }

    showTutorialPrompt({ force = false } = {}) {
        if (!force && !this.shouldShowTutorial()) {
            return;
        }

        if (this.isActive || this.prompt) {
            return;
        }

        const prompt = document.createElement('div');
        prompt.id = 'tutorialPrompt';
        prompt.className = 'tutorial-prompt';
        prompt.innerHTML = `
            <div class="tutorial-prompt-content">
                <div class="tutorial-prompt-icon">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <div class="tutorial-prompt-text">
                    <h3>New to QuillTalk?</h3>
                    <p>Take a quick tour to learn all the features!</p>
                </div>
                <div class="tutorial-prompt-actions">
                    <button id="tutorialStart" class="tutorial-btn tutorial-btn-primary">
                        <i class="fa-solid fa-play"></i>
                        Start Tour
                    </button>
                    <button id="tutorialSkip" class="tutorial-btn tutorial-btn-secondary">
                        Skip
                    </button>
                </div>
                <button id="tutorialLater" class="tutorial-prompt-link" type="button">
                    Later
                </button>
            </div>
        `;

        document.body.appendChild(prompt);
        this.prompt = prompt;

        document.getElementById('tutorialStart').addEventListener('click', () => {
            this.start();
        });

        document.getElementById('tutorialSkip').addEventListener('click', () => {
            this.skipTutorial();
        });

        document.getElementById('tutorialLater').addEventListener('click', () => {
            this.delayTutorialPrompt();
        });
    }

    start() {
        this.clearPrompt();
        this.clearPendingPromptTimeout();
        this.clearReminder({ emit: false });

        if (this.isActive) {
            this.end();
        }

        this.isActive = true;
        this.currentStep = 0;
        this.renderToken = 0;
        this.createOverlay();
        this.setupKeyboardNavigation();
        this.setupPositionTracking();
        this.showStep(0);
        this.updateTutorialStatusUi();
    }

    startFromSettings() {
        this.start();

        if (typeof window.closeAccountMenu === 'function') {
            window.closeAccountMenu();
        }

        window.requestAnimationFrame(() => {
            if (this.isActive) {
                this.refreshCurrentStepPosition();
            }
        });

        window.setTimeout(() => {
            if (this.isActive) {
                this.refreshCurrentStepPosition();
            }
        }, 180);
    }

    delayTutorialPrompt() {
        if (this.checkTutorialStatus()) {
            return;
        }

        this.clearPendingPromptTimeout();
        this.clearPrompt();
        this.persistReminderTimestamp(Date.now() + TUTORIAL_REMINDER_DELAY_MS);
        this.scheduleReminderPrompt();
        this.updateTutorialStatusUi();
    }

    setupKeyboardNavigation() {
        this.keyboardHandler = event => {
            if (!this.isActive || event.altKey || event.ctrlKey || event.metaKey) {
                return;
            }

            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                if (this.currentStep > 0) {
                    this.showStep(this.currentStep - 1);
                }
            } else if (event.key === 'ArrowRight') {
                event.preventDefault();
                if (this.currentStep < TUTORIAL_STEPS.length - 1) {
                    this.showStep(this.currentStep + 1);
                } else {
                    this.completeTutorial();
                }
            }
        };
        
        document.addEventListener('keydown', this.keyboardHandler);
    }

    setupPositionTracking() {
        this.positionHandler = () => {
            if (!this.isActive) {
                return;
            }

            window.requestAnimationFrame(() => {
                this.refreshCurrentStepPosition();
            });
        };

        window.addEventListener('resize', this.positionHandler);
        document.addEventListener('scroll', this.positionHandler, true);
    }

    createOverlay() {
        this.overlay = document.createElement('div');
        this.overlay.id = 'tutorialOverlay';
        this.overlay.className = 'tutorial-overlay';
        document.body.appendChild(this.overlay);

        this.tooltip = document.createElement('div');
        this.tooltip.id = 'tutorialTooltip';
        this.tooltip.className = 'tutorial-tooltip';
        document.body.appendChild(this.tooltip);
    }

    clearStepInteraction() {
        if (typeof this.stepInteractionCleanup === 'function') {
            this.stepInteractionCleanup();
        }
        this.stepInteractionCleanup = null;
    }

    clearDecorations() {
        this.decoratedElements.forEach(({ element, className }) => {
            if (element && className) {
                element.classList.remove(className);
            }
        });
        this.decoratedElements = [];

        if (this.arrow) {
            this.arrow.remove();
            this.arrow = null;
        }
    }

    decorateElement(element, className) {
        if (!element || !className) {
            return;
        }

        element.classList.add(className);
        this.decoratedElements.push({ element, className });
    }

    async scrollTargetIntoView(targetElement) {
        if (!targetElement || !this.isElementVisible(targetElement)) {
            return;
        }

        targetElement.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
            inline: 'center'
        });
        await this.wait(180);
    }

    measureTooltip() {
        this.tooltip.style.visibility = 'hidden';
        this.tooltip.style.left = '12px';
        this.tooltip.style.top = '12px';
        this.tooltip.style.transform = 'none';
        this.tooltip.style.maxWidth = `${Math.min(460, window.innerWidth - 24)}px`;

        return {
            width: Math.min(this.tooltip.offsetWidth || 420, window.innerWidth - 24),
            height: Math.min(this.tooltip.offsetHeight || 240, window.innerHeight - 24)
        };
    }

    getStepAnchor(step) {
        const targetElement = step && step.target ? this.findVisibleElement(step.target, { allowDisabled: true }) : null;
        const highlightElement = step && step.highlight ? this.findVisibleElement(step.highlight, { allowDisabled: true }) : null;
        return targetElement || highlightElement || null;
    }

    applyDecorations(step, targetElement, highlightElement, dropdownContext) {
        if (targetElement) {
            this.decorateElement(targetElement, 'tutorial-highlight');
        }

        if (highlightElement && highlightElement !== targetElement) {
            this.decorateElement(highlightElement, 'tutorial-highlight');
        }

        const menuSurface = targetElement
            ? targetElement.closest('.dropdown-menu, .chat-actions-dropdown-menu, .header-overflow-menu')
            : null;
        if (menuSurface) {
            this.decorateElement(menuSurface, 'tutorial-elevated-surface');
        }

        if (dropdownContext && dropdownContext.trigger) {
            this.decorateElement(dropdownContext.trigger, 'tutorial-elevated-surface');
        }

        if (dropdownContext && dropdownContext.menu) {
            this.decorateElement(dropdownContext.menu, 'tutorial-elevated-surface');
        }

        if (step.showArrow && targetElement) {
            this.showAnimatedArrow(targetElement);
        }
    }

    setupStepInteraction(step, targetElement) {
        if (!step.waitForClick || !targetElement) {
            return;
        }

        const advance = () => {
            this.clearStepInteraction();
            window.setTimeout(() => {
                if (!this.isActive) {
                    return;
                }

                if (this.currentStep >= TUTORIAL_STEPS.length - 1) {
                    this.completeTutorial();
                } else {
                    this.showStep(this.currentStep + 1);
                }
            }, 240);
        };

        const clickHandler = () => advance();
        const keyHandler = event => {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }
            event.preventDefault();
            advance();
        };

        targetElement.addEventListener('click', clickHandler);
        targetElement.addEventListener('keydown', keyHandler);
        this.stepInteractionCleanup = () => {
            targetElement.removeEventListener('click', clickHandler);
            targetElement.removeEventListener('keydown', keyHandler);
        };
    }

    async showStep(stepIndex) {
        if (stepIndex < 0 || stepIndex >= TUTORIAL_STEPS.length) {
            this.end();
            return;
        }

        const renderToken = ++this.renderToken;
        this.currentStep = stepIndex;
        const step = TUTORIAL_STEPS[stepIndex];

        this.clearStepInteraction();
        this.clearDecorations();

        if (!step.requiresDropdown) {
            this.closeTransientUi();
        }

        await this.ensureStepPrerequisites(step);
        if (!this.isActive || renderToken !== this.renderToken) {
            return;
        }

        let dropdownContext = { trigger: null, menu: null };
        if (step.requiresDropdown) {
            dropdownContext = await this.ensureDropdownOpen(step.requiresDropdown);
        }

        if (!this.isActive || renderToken !== this.renderToken) {
            return;
        }

        const targetElement = step.target ? this.findVisibleElement(step.target, { allowDisabled: true }) : null;
        const highlightElement = step.highlight ? this.findVisibleElement(step.highlight, { allowDisabled: true }) : targetElement;
        const anchorElement = targetElement || highlightElement || null;

        await this.scrollTargetIntoView(anchorElement);
        if (!this.isActive || renderToken !== this.renderToken) {
            return;
        }

        this.updateTooltipContent(step, stepIndex);
        this.applyDecorations(step, targetElement, highlightElement, dropdownContext);
        this.positionTooltip(step, anchorElement);
        this.setupStepInteraction(step, targetElement || highlightElement);
    }

    refreshCurrentStepPosition() {
        if (!this.isActive || !this.tooltip) {
            return;
        }

        const step = TUTORIAL_STEPS[this.currentStep];
        if (!step) {
            return;
        }

        const anchorElement = this.getStepAnchor(step);
        this.positionTooltip(step, anchorElement);

        if (step.showArrow) {
            if (this.arrow) {
                this.arrow.remove();
                this.arrow = null;
            }
            if (anchorElement) {
                this.showAnimatedArrow(anchorElement);
            }
        }
    }

    showAnimatedArrow(targetElement) {
        if (!targetElement) {
            return;
        }

        const rect = targetElement.getBoundingClientRect();
        const placeAbove = rect.top > 56;

        this.arrow = document.createElement('div');
        this.arrow.className = 'tutorial-animated-arrow';
        this.arrow.innerHTML = placeAbove
            ? '<i class="fa-solid fa-arrow-down"></i>'
            : '<i class="fa-solid fa-arrow-up"></i>';
        document.body.appendChild(this.arrow);

        this.arrow.style.left = `${Math.round(rect.left + rect.width / 2)}px`;
        this.arrow.style.top = placeAbove
            ? `${Math.round(rect.top - 42)}px`
            : `${Math.round(rect.bottom + 10)}px`;
        this.arrow.style.transform = 'translateX(-50%)';
    }

    positionTooltip(step, targetElement) {
        const edgePadding = 12;
        const gap = 18;
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;
        const size = this.measureTooltip();

        let left = Math.max(edgePadding, Math.round((viewportWidth - size.width) / 2));
        let top = Math.max(edgePadding, Math.round((viewportHeight - size.height) / 2));

        if (targetElement && this.isElementVisible(targetElement) && step.position !== 'center') {
            const rect = targetElement.getBoundingClientRect();
            const centerX = rect.left + rect.width / 2;
            const centerY = rect.top + rect.height / 2;

            switch (step.position) {
                case 'top':
                    left = Math.round(centerX - size.width / 2);
                    top = Math.round(rect.top - size.height - gap);
                    if (top < edgePadding) {
                        top = Math.round(rect.bottom + gap);
                    }
                    break;
                case 'bottom':
                    left = Math.round(centerX - size.width / 2);
                    top = Math.round(rect.bottom + gap);
                    if (top + size.height > viewportHeight - edgePadding) {
                        top = Math.round(rect.top - size.height - gap);
                    }
                    break;
                case 'left':
                    left = Math.round(rect.left - size.width - gap);
                    top = Math.round(centerY - size.height / 2);
                    if (left < edgePadding) {
                        left = Math.round(rect.right + gap);
                    }
                    break;
                case 'right':
                    left = Math.round(rect.right + gap);
                    top = Math.round(centerY - size.height / 2);
                    if (left + size.width > viewportWidth - edgePadding) {
                        left = Math.round(rect.left - size.width - gap);
                    }
                    break;
            }
        }

        this.tooltip.style.left = `${Math.max(edgePadding, Math.min(viewportWidth - size.width - edgePadding, left))}px`;
        this.tooltip.style.top = `${Math.max(edgePadding, Math.min(viewportHeight - size.height - edgePadding, top))}px`;
        this.tooltip.style.transform = 'none';
        this.tooltip.style.visibility = 'visible';
    }

    updateTooltipContent(step, stepIndex) {
        this.tooltip.innerHTML = `
            <div class="tutorial-tooltip-header">
                <span class="tutorial-step-counter">${stepIndex + 1} / ${TUTORIAL_STEPS.length}</span>
                <button class="tutorial-close-btn" id="tutorialClose" type="button" aria-label="Close tutorial">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="tutorial-tooltip-body">
                <h3>${step.title}</h3>
                <p>${step.description}</p>
            </div>
            <div class="tutorial-tooltip-footer">
                <button class="tutorial-btn tutorial-btn-secondary" id="tutorialPrev" type="button" ${stepIndex === 0 ? 'disabled' : ''}>
                    <i class="fa-solid fa-arrow-left"></i>
                    Previous
                </button>
                <button class="tutorial-btn tutorial-btn-primary" id="tutorialNext" type="button">
                    ${stepIndex === TUTORIAL_STEPS.length - 1 ? 'Finish' : 'Next'}
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
            </div>
            <div class="tutorial-skip-link">
                <button id="tutorialSkipAll" type="button">Skip tutorial</button>
                <span class="tutorial-keyboard-hint">Use ← → arrow keys to navigate</span>
            </div>
        `;

        document.getElementById('tutorialClose').addEventListener('click', () => this.end());
        document.getElementById('tutorialPrev').addEventListener('click', () => this.showStep(stepIndex - 1));
        document.getElementById('tutorialNext').addEventListener('click', () => {
            if (stepIndex === TUTORIAL_STEPS.length - 1) {
                this.completeTutorial();
            } else {
                this.showStep(stepIndex + 1);
            }
        });
        document.getElementById('tutorialSkipAll').addEventListener('click', () => this.skipTutorial());
    }

    skipTutorial() {
        this.hasCompletedTutorial = false;
        this.hasSkippedTutorial = true;
        this.clearPrompt();
        this.clearReminder({ emit: false });
        this.updateTutorialStatusUi();
        this.saveTutorialStatus(false, true);
        this.end();
    }

    completeTutorial() {
        this.hasCompletedTutorial = true;
        this.hasSkippedTutorial = false;
        this.clearPrompt();
        this.clearReminder({ emit: false });
        this.updateTutorialStatusUi();
        this.saveTutorialStatus(true, false);
        this.end();
        this.showCompletionMessage();
    }

    showCompletionMessage() {
        const message = document.createElement('div');
        message.className = 'tutorial-completion-message';
        message.innerHTML = `
            <div class="tutorial-completion-content">
                <div class="tutorial-completion-icon">
                    <i class="fa-solid fa-check-circle"></i>
                </div>
                <h3>Tutorial Complete!</h3>
                <p>You're all set to use QuillTalk. Happy chatting!</p>
            </div>
        `;
        document.body.appendChild(message);

        setTimeout(() => {
            message.classList.add('fade-out');
            setTimeout(() => message.remove(), 500);
        }, 3000);
    }

    end() {
        this.isActive = false;
        this.renderToken += 1;

        this.clearStepInteraction();
        this.clearDecorations();
        this.closeTransientUi();

        if (this.keyboardHandler) {
            document.removeEventListener('keydown', this.keyboardHandler);
            this.keyboardHandler = null;
        }

        if (this.positionHandler) {
            window.removeEventListener('resize', this.positionHandler);
            document.removeEventListener('scroll', this.positionHandler, true);
            this.positionHandler = null;
        }

        if (this.overlay) {
            this.overlay.remove();
            this.overlay = null;
        }

        if (this.tooltip) {
            this.tooltip.remove();
            this.tooltip = null;
        }
    }

    restart() {
        this.clearPendingPromptTimeout();
        this.clearPrompt();
        this.clearReminder({ emit: false });
        this.saveTutorialStatus(false, false);
        this.hasCompletedTutorial = false;
        this.hasSkippedTutorial = false;
        this.updateTutorialStatusUi();
        this.start();
    }
}

// Initialize tutorial system
const tutorialSystem = new TutorialSystem();

// Auto-start for new users
document.addEventListener('DOMContentLoaded', () => {
    tutorialSystem.init();
});

// Expose globally for manual restart
window.restartTutorial = () => tutorialSystem.restart();
window.startTutorialFromSettings = () => tutorialSystem.startFromSettings();
