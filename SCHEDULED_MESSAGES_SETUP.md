# Scheduled Messages Setup

## Overview
The scheduled messages feature allows users to schedule messages to be sent at a future time.

## Files Created
1. `send_scheduled_message.php` - Creates a scheduled message
2. `process_scheduled_messages.php` - Processes and sends due scheduled messages
3. `send_scheduled_now.php` - Sends a scheduled message immediately
4. `get_scheduled_messages.php` - Gets user's pending scheduled messages

## Database
A new table `scheduled_messages` is automatically created when the first scheduled message is sent.

## Cron Job Setup (Optional but Recommended)

For reliable scheduled message delivery, set up a cron job to run every minute:

### On Hostinger (via cPanel):
1. Log into your Hostinger control panel
2. Go to "Advanced" → "Cron Jobs"
3. Add a new cron job:
   - **Minute**: `*`
   - **Hour**: `*`
   - **Day**: `*`
   - **Month**: `*`
   - **Weekday**: `*`
   - **Command**: `curl https://quilltalk.org/process_scheduled_messages.php`

### Alternative (if cURL not available):
```
* * * * * php /path/to/your/htdocs/process_scheduled_messages.php
```

## How It Works

1. **User schedules a message**: Clicks + menu → "Schedule message" → Selects time → Types message → Sends
2. **Message is stored**: Saved in `scheduled_messages` table with status 'pending'
3. **Message displays**: Shows in chat with greyed out appearance and "Scheduled for [time]" indicator
4. **Processing**: 
   - Client-side: Polls every minute to trigger processing
   - Server-side (if cron set up): Runs every minute automatically
5. **Message sends**: When scheduled time arrives, message is sent and styling updates
6. **Send now option**: User can click "Send now" in message menu to send immediately

## Features

- ✅ Schedule messages for future delivery
- ✅ Visual indicator showing scheduled time (only visible to sender)
- ✅ Greyed out appearance until sent
- ✅ "Send now" option in message menu (only for sender, only before scheduled time)
- ✅ Automatic sending when time arrives
- ✅ Works without cron job (client-side polling as backup)

## Notes

- Scheduled messages are only visible to the sender until they're sent
- Minimum schedule time is 1 minute in the future
- Messages are processed every minute (or when any user is active if no cron job)
- The "Send now" option only appears for the message sender and only before the scheduled time
