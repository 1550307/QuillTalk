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
        title: "Chat Header",
        description: "See who you're chatting with, their online status, and quick access to call and info buttons.",
        target: ".chat-header",
        position: "bottom"
    },
    {
        title: "Voice & Video Calls",
        description: "Click the phone icon to start a voice call, or the video icon for a video call. Works for both 1-on-1 and group chats!",
        target: ".call-icon-desktop",
        position: "bottom"
    },
    {
        title: "Contact Info",
        description: "Click the info icon to see contact details, set nicknames, block users, or manage group settings.",
        target: ".info-icon-btn",
        position: "bottom"
    },
    {
        title: "Message Input",
        description: "Type your messages here. Press Enter to send, or Shift+Enter for a new line.",
        target: "#messageInput",
        position: "top"
    },
    {
        title: "Mentions & Commands",
        description: "Type @ to mention someone or / to use commands. Mentions and commands appear in bold!",
        target: "#messageInput",
        position: "top"
    },
    {
        title: "Send Images & Files",
        description: "Click the image icon to send photos, videos, or any file. You can also drag and drop files directly into the chat!",
        target: "#attachmentBtn",
        position: "top"
    },
    {
        title: "Voice Messages",
        description: "Hold the microphone button to record a voice message. Release to send, or slide to cancel.",
        target: "#voiceRecordBtn",
        position: "top"
    },
    {
        title: "Emojis",
        description: "Click the emoji button to add emojis to your message. Your recently used emojis appear first!",
        target: "#emojiBtn",
        position: "top"
    },
    {
        title: "More Actions (+)",
        description: "Click the + button for more options: send GIFs, create polls, or schedule messages. Click it now!",
        target: "#chatActionsBtn",
        position: "top",
        interactive: true,
        waitForClick: true,
        showArrow: true
    },
    {
        title: "Send GIFs",
        description: "Search and send animated GIFs from Tenor. Perfect for expressing emotions!",
        target: "[data-action='gif']",
        position: "top",
        interactive: true,
        requiresDropdown: "#chatActionsBtn"
    },
    {
        title: "Create Polls",
        description: "Create polls with multiple options. Great for making group decisions! Set a duration and let people vote.",
        target: "[data-action='poll']",
        position: "top",
        interactive: true,
        requiresDropdown: "#chatActionsBtn"
    },
    {
        title: "Schedule Messages",
        description: "Schedule messages to be sent at a specific time. Perfect for reminders or sending messages when you're busy!",
        target: "[data-action='schedule']",
        position: "top",
        interactive: true,
        requiresDropdown: "#chatActionsBtn"
    },
    {
        title: "Message Actions",
        description: "Click the three dots on any message to reply, forward, edit, delete, or react with emojis.",
        target: ".message-actions-btn",
        position: "left",
        highlight: ".message"
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
        position: "bottom"
    },
    {
        title: "Online Status",
        description: "Green dot = online, gray dot = offline. See who's available to chat right now!",
        target: ".chat-header-status-dot",
        position: "bottom"
    },
    {
        title: "Settings Menu",
        description: "Click your profile picture to access settings: change your profile, password, email, theme, and more!",
        target: "#accountMenuBtn",
        position: "left"
    },
    {
        title: "Account Settings",
        description: "Update your display name, username, bio, profile picture, password, and email address.",
        target: null,
        position: "center"
    },
    {
        title: "Appearance Settings",
        description: "Customize QuillTalk! Choose between light and dark mode, and pick your favorite accent color.",
        target: null,
        position: "center"
    },
    {
        title: "Notification Settings",
        description: "Control how you get notified. Enable/disable sounds, desktop notifications, and more.",
        target: null,
        position: "center"
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
        position: "bottom"
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

class TutorialSystem {
    constructor() {
        this.currentStep = 0;
        this.isActive = false;
        this.overlay = null;
        this.tooltip = null;
        this.arrow = null;
        this.keyboardHandler = null;
        // Get tutorial status from PHP variables (passed from database)
        this.hasCompletedTutorial = typeof tutorialCompleted !== 'undefined' ? tutorialCompleted === 1 : false;
        this.hasSkippedTutorial = typeof tutorialSkipped !== 'undefined' ? tutorialSkipped === 1 : false;
    }

    checkTutorialStatus() {
        return this.hasCompletedTutorial || this.hasSkippedTutorial;
    }

    shouldShowTutorial() {
        return !this.checkTutorialStatus();
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
            } else {
                console.error('Failed to save tutorial status:', data.error);
            }
        } catch (e) {
            console.error('Failed to save tutorial status:', e);
        }
    }

    init() {
        if (!this.shouldShowTutorial()) {
            return;
        }

        setTimeout(() => {
            this.showTutorialPrompt();
        }, 2000);
    }

    showTutorialPrompt() {
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
            </div>
        `;

        document.body.appendChild(prompt);

        document.getElementById('tutorialStart').addEventListener('click', () => {
            prompt.remove();
            this.start();
        });

        document.getElementById('tutorialSkip').addEventListener('click', () => {
            this.skipTutorial();
            prompt.remove();
        });
    }

    start() {
        this.isActive = true;
        this.currentStep = 0;
        this.createOverlay();
        this.setupKeyboardNavigation();
        this.showStep(0);
    }

    setupKeyboardNavigation() {
        this.keyboardHandler = (e) => {
            if (!this.isActive) return;
            
            if (e.key === 'ArrowLeft') {
                e.preventDefault();
                if (this.currentStep > 0) {
                    this.showStep(this.currentStep - 1);
                }
            } else if (e.key === 'ArrowRight') {
                e.preventDefault();
                if (this.currentStep < TUTORIAL_STEPS.length - 1) {
                    this.showStep(this.currentStep + 1);
                } else {
                    this.completeTutorial();
                }
            }
        };
        
        document.addEventListener('keydown', this.keyboardHandler);
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

    showStep(stepIndex) {
        if (stepIndex < 0 || stepIndex >= TUTORIAL_STEPS.length) {
            this.end();
            return;
        }

        this.currentStep = stepIndex;
        const step = TUTORIAL_STEPS[stepIndex];

        // Remove previous arrow
        if (this.arrow) {
            this.arrow.remove();
            this.arrow = null;
        }

        // Clear previous highlights
        document.querySelectorAll('.tutorial-highlight').forEach(el => {
            el.classList.remove('tutorial-highlight');
            el.style.pointerEvents = '';
            el.style.position = '';
            el.style.zIndex = '';
        });

        // Handle target element
        let targetElement = null;
        if (step.target) {
            // If step requires dropdown, ensure it's open
            if (step.requiresDropdown) {
                const dropdownTrigger = document.querySelector(step.requiresDropdown);
                if (dropdownTrigger) {
                    dropdownTrigger.click();
                    // Wait for dropdown to open
                    setTimeout(() => {
                        targetElement = document.querySelector(step.target);
                        this.highlightAndPositionElement(targetElement, step);
                    }, 300);
                    return;
                }
            }
            
            targetElement = document.querySelector(step.target);
            this.highlightAndPositionElement(targetElement, step);
        } else {
            this.positionTooltip(step, null);
            this.updateTooltipContent(step, stepIndex);
        }
    }

    highlightAndPositionElement(targetElement, step) {
        if (targetElement) {
            // Make element interactive and visible
            targetElement.classList.add('tutorial-highlight');
            targetElement.style.pointerEvents = 'auto';
            targetElement.style.position = 'relative';
            targetElement.style.zIndex = '10000';
            
            // Scroll into view with better positioning
            targetElement.scrollIntoView({ 
                behavior: 'smooth', 
                block: 'center',
                inline: 'center'
            });

            // Show animated arrow if needed
            if (step.showArrow) {
                this.showAnimatedArrow(targetElement);
            }
        }

        this.positionTooltip(step, targetElement);
        this.updateTooltipContent(step, this.currentStep);
    }

    showAnimatedArrow(targetElement) {
        this.arrow = document.createElement('div');
        this.arrow.className = 'tutorial-animated-arrow';
        this.arrow.innerHTML = '<i class="fa-solid fa-arrow-down"></i>';
        document.body.appendChild(this.arrow);

        const rect = targetElement.getBoundingClientRect();
        this.arrow.style.position = 'fixed';
        this.arrow.style.left = `${rect.left + rect.width / 2}px`;
        this.arrow.style.top = `${rect.top - 40}px`;
        this.arrow.style.transform = 'translateX(-50%)';
        this.arrow.style.zIndex = '10001';
    }

    positionTooltip(step, targetElement) {
        if (!targetElement || step.position === 'center') {
            this.tooltip.style.position = 'fixed';
            this.tooltip.style.top = '50%';
            this.tooltip.style.left = '50%';
            this.tooltip.style.transform = 'translate(-50%, -50%)';
            this.tooltip.style.maxWidth = '500px';
            return;
        }

        const rect = targetElement.getBoundingClientRect();
        const tooltipWidth = 400;
        const padding = 20;
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;

        this.tooltip.style.position = 'fixed';
        this.tooltip.style.maxWidth = `${tooltipWidth}px`;

        let left, top, transform;

        switch (step.position) {
            case 'top':
                left = rect.left + rect.width / 2;
                top = rect.top - padding;
                transform = 'translate(-50%, -100%)';
                
                // Ensure tooltip stays in viewport
                if (top < 10) {
                    // Switch to bottom if not enough space on top
                    top = rect.bottom + padding;
                    transform = 'translateX(-50%)';
                }
                break;
                
            case 'bottom':
                left = rect.left + rect.width / 2;
                top = rect.bottom + padding;
                transform = 'translateX(-50%)';
                
                // Check if tooltip would go off bottom
                if (top + 300 > viewportHeight) {
                    top = rect.top - padding;
                    transform = 'translate(-50%, -100%)';
                }
                break;
                
            case 'left':
                left = rect.left - padding;
                top = rect.top + rect.height / 2;
                transform = 'translate(-100%, -50%)';
                
                // Ensure tooltip stays in viewport
                if (left - tooltipWidth < 10) {
                    left = rect.right + padding;
                    transform = 'translateY(-50%)';
                }
                break;
                
            case 'right':
                left = rect.right + padding;
                top = rect.top + rect.height / 2;
                transform = 'translateY(-50%)';
                
                // Ensure tooltip stays in viewport
                if (left + tooltipWidth > viewportWidth - 10) {
                    left = rect.left - padding;
                    transform = 'translate(-100%, -50%)';
                }
                break;
        }

        // Final boundary checks
        this.tooltip.style.left = `${Math.max(10, Math.min(viewportWidth - tooltipWidth - 10, left))}px`;
        this.tooltip.style.top = `${Math.max(10, Math.min(viewportHeight - 100, top))}px`;
        this.tooltip.style.transform = transform;
    }

    updateTooltipContent(step, stepIndex) {
        this.tooltip.innerHTML = `
            <div class="tutorial-tooltip-header">
                <span class="tutorial-step-counter">${stepIndex + 1} / ${TUTORIAL_STEPS.length}</span>
                <button class="tutorial-close-btn" id="tutorialClose">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="tutorial-tooltip-body">
                <h3>${step.title}</h3>
                <p>${step.description}</p>
            </div>
            <div class="tutorial-tooltip-footer">
                <button class="tutorial-btn tutorial-btn-secondary" id="tutorialPrev" ${stepIndex === 0 ? 'disabled' : ''}>
                    <i class="fa-solid fa-arrow-left"></i>
                    Previous
                </button>
                <button class="tutorial-btn tutorial-btn-primary" id="tutorialNext">
                    ${stepIndex === TUTORIAL_STEPS.length - 1 ? 'Finish' : 'Next'}
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
            </div>
            <div class="tutorial-skip-link">
                <button id="tutorialSkipAll">Skip tutorial</button>
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
        this.saveTutorialStatus(false, true);
        this.end();
    }

    completeTutorial() {
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
        
        if (this.keyboardHandler) {
            document.removeEventListener('keydown', this.keyboardHandler);
            this.keyboardHandler = null;
        }
        
        if (this.arrow) {
            this.arrow.remove();
            this.arrow = null;
        }
        
        if (this.overlay) {
            this.overlay.remove();
            this.overlay = null;
        }
        
        if (this.tooltip) {
            this.tooltip.remove();
            this.tooltip = null;
        }
        
        document.querySelectorAll('.tutorial-highlight').forEach(el => {
            el.classList.remove('tutorial-highlight');
            el.style.pointerEvents = '';
            el.style.position = '';
            el.style.zIndex = '';
        });
    }

    restart() {
        this.saveTutorialStatus(false, false);
        this.hasCompletedTutorial = false;
        this.hasSkippedTutorial = false;
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
