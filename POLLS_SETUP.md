# Polls Feature Setup

## Database Setup

Run the SQL schema to create the required tables:

```bash
mysql -u your_username -p your_database < polls_schema.sql
```

Or manually execute the SQL in `polls_schema.sql` through phpMyAdmin or your preferred database tool.

## Features Included

### Poll Creation
- Click the + button next to the image/voice buttons in chat
- Select "Start a poll" from the dropdown
- Customize poll with:
  - Title
  - Multiple options (minimum 2)
  - Optional images for each option
  - End conditions (by date, by response count, or both)

### Poll Interaction
- Vote by clicking on an option
- See real-time vote percentages and counts
- Click vote counts to see who voted for each option
- Clear your response (if you've voted)
- End poll early (if you're the creator)

### Poll Display
- Shows poll title
- Lists all options with optional images
- Displays vote statistics (percentage and count)
- Shows end conditions (date/response count)
- Shows time remaining
- Indicates when poll has ended

### Permissions
- Only poll creator can end the poll early
- Users can change their vote anytime before poll ends
- Poll automatically ends when:
  - End date is reached
  - Response count target is met
  - Creator manually ends it

## File Structure

### Backend PHP Files
- `create_poll.php` - Creates new polls
- `vote_poll.php` - Records user votes
- `get_poll_data.php` - Fetches poll data and results
- `clear_poll_vote.php` - Removes user's vote
- `end_poll.php` - Ends poll (creator only)
- `get_poll_option_voters.php` - Gets list of voters for an option
- `upload_poll_image.php` - Handles poll option image uploads

### Database Tables
- `polls` - Stores poll metadata
- `poll_options` - Stores poll options
- `poll_votes` - Stores user votes

### Frontend
- Poll UI components in `app.php`
- Poll JavaScript functions
- Poll CSS styles

## Usage

1. Select a chat (individual or group)
2. Click the + button in the chat input area
3. Select "Start a poll"
4. Fill in poll details
5. Click "Start Poll"

The poll will appear as a message in the chat and all participants can vote on it.

## Notes

- Polls work in both individual and group chats
- Images are optional for poll options
- At least one end condition must be set (date or response count)
- If both conditions are set, poll ends when either is met
- Polls are timezone-aware and display in user's local time
