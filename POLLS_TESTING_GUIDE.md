# Polls Feature Testing Guide

## Database Setup

1. Run the SQL schema file to create the tables:
   ```sql
   -- Execute htdocs/polls_schema.sql
   ```

2. The schema creates three tables WITHOUT foreign keys to avoid constraint errors:
   - `polls` - Main poll data
   - `poll_options` - Poll options with text and optional images
   - `poll_votes` - User votes on poll options

## Testing Steps

### 1. Create a Poll in Private Chat

1. Open a chat with a friend (must be accepted friend)
2. Click the **+** button (left of image/voice buttons)
3. Select **"Start a poll"** from the dropdown
4. Fill in the poll form:
   - Poll Title: "What's your favorite color?"
   - Option 1: "Red"
   - Option 2: "Blue"
   - Click "New option +" to add Option 3: "Green"
5. Set end conditions (optional):
   - End by date: Pick a future date/time
   - OR end by responses: Enter a number (e.g., 5)
6. Click **"Start Poll"**

### 2. Create a Poll in Group Chat

1. Open a group chat (you must be a member)
2. Click the **+** button
3. Select **"Start a poll"**
4. Create a poll with at least 2 options
5. Click **"Start Poll"**

### 3. Vote on a Poll

1. View a poll message
2. Click on any option to vote
3. The option should highlight with the theme color
4. You should see:
   - Percentage for each option
   - Vote counts
   - Total responses

### 4. Change Your Vote

1. Click on a different option
2. Your vote should update automatically
3. Percentages should recalculate

### 5. View Who Voted

1. After voting, click the "(X) chose this response" link
2. A modal should open showing:
   - Profile pictures
   - Display names
   - Usernames
   - Online status

### 6. Clear Your Vote

1. After voting, click **"Clear response"** button
2. Confirm the action
3. Your vote should be removed
4. Percentages should update

### 7. End a Poll (Creator Only)

1. As the poll creator, click **"End poll"** button
2. Confirm the action
3. Poll should show "Poll ended"
4. No one can vote anymore
5. Clicking options shows error: "This poll is no longer accepting responses"

### 8. Auto-End by Date

1. Create a poll with an end date in the past (or wait for it to expire)
2. Poll should automatically end when viewed
3. Shows "Poll ended"

### 9. Auto-End by Response Count

1. Create a poll with "End by responses: 3"
2. Have 3 different users vote
3. Poll should automatically end after 3rd vote

### 10. Poll Message Actions

1. Click the three dots on a poll message
2. Should show same options as regular messages:
   - Reply
   - Forward
   - Delete (if you sent it)
   - etc.

## Expected Behavior

✅ Only accepted friends can create polls in private chats
✅ Only group members can create polls in group chats
✅ Polls show real-time vote percentages
✅ Users can change their vote anytime (unless poll ended)
✅ Poll creator can end poll manually
✅ Polls auto-end by date or response count
✅ Ended polls don't accept new votes
✅ Poll images are optional
✅ Minimum 2 options required
✅ Dates auto-convert to user's timezone

## Troubleshooting

### Can't create poll
- Check if you're friends with the user (status = 'accepted')
- Check if you're a member of the group
- Check browser console for errors

### Poll not showing
- Check if message starts with `__POLL__:`
- Check browser console for fetch errors
- Verify poll_id exists in database

### Votes not updating
- Check `vote_poll.php` response in Network tab
- Verify user is authenticated
- Check if poll has ended

### Foreign key errors
- The schema is designed WITHOUT foreign keys
- If you added them manually, remove them
- Use the provided `polls_schema.sql` file

## Files Involved

### Backend PHP
- `create_poll.php` - Create new poll
- `vote_poll.php` - Vote on poll option
- `clear_poll_vote.php` - Remove user's vote
- `end_poll.php` - End poll manually
- `get_poll_data.php` - Fetch poll data
- `get_poll_option_voters.php` - Get voters for an option

### Frontend (app.php)
- Poll modal HTML (lines ~9226-9278)
- Poll responses modal HTML (lines ~9280-9293)
- Poll CSS styles (lines ~5302-5648)
- Poll JavaScript functions (lines ~23844-24384)

### Database
- `polls_schema.sql` - Database schema

## Success Indicators

When everything works correctly:
1. ✅ Poll button appears in chat actions dropdown
2. ✅ Poll modal opens with all fields
3. ✅ Can add/remove options dynamically
4. ✅ Poll message appears in chat with proper styling
5. ✅ Voting updates percentages in real-time
6. ✅ Can view voters list
7. ✅ Can clear vote and end poll
8. ✅ Auto-ending works by date and response count

Enjoy your new polls feature! 🎉
