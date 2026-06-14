# Polls Feature - Activation Checklist

## Prerequisites
- QuillTalk application is installed and running
- Database access (MySQL/MariaDB)
- PHP 7.4+ with PDO extension
- Web server (Apache/Nginx)

## Step-by-Step Activation

### 1. Database Setup ✓
Run the SQL schema to create required tables:

```bash
# Option A: Command line
mysql -u your_username -p your_database < polls_schema.sql

# Option B: phpMyAdmin
# - Open phpMyAdmin
# - Select your database
# - Go to "Import" tab
# - Choose polls_schema.sql
# - Click "Go"
```

Verify tables were created:
```sql
SHOW TABLES LIKE 'poll%';
```

You should see:
- `polls`
- `poll_options`
- `poll_votes`

### 2. File Permissions ✓
Ensure the uploads directory exists and is writable:

```bash
mkdir -p htdocs/uploads/polls
chmod 755 htdocs/uploads/polls
```

### 3. Test the Feature

#### Test 1: Open the Application
1. Open QuillTalk in your browser
2. Log in with your account
3. Open the browser console (F12)
4. Look for: `Poll event listeners initialized`

#### Test 2: Create a Poll
1. Select a chat (individual or group)
2. Click the **+** button next to the image button
3. Click "Start a poll"
4. Fill in:
   - Poll title: "What's your favorite color?"
   - Option 1: "Red"
   - Option 2: "Blue"
   - Click "New option" and add "Green"
   - Check "End after responses"
   - Enter "10" responses
5. Click "Start Poll"
6. Verify poll appears in chat

#### Test 3: Vote on a Poll
1. Click on an option in the poll
2. Verify it highlights
3. Verify percentage appears
4. Click another option
5. Verify vote changes

#### Test 4: View Voters
1. After voting, click the vote count link
2. Verify modal opens showing voters
3. Verify your name appears
4. Close modal

#### Test 5: Clear Vote
1. Click "Clear response" button
2. Confirm the action
3. Verify vote is removed
4. Verify percentages update

#### Test 6: End Poll (Creator Only)
1. As poll creator, click "End poll"
2. Confirm the action
3. Verify "Poll ended" badge appears
4. Try to vote - should show error

#### Test 7: Poll with Date
1. Create a new poll
2. Check "End by date"
3. Select a date 1 hour from now
4. Create poll
5. Verify "Time left" displays

#### Test 8: Poll with Image
1. Create a new poll
2. For Option 1, upload an image
3. Create poll
4. Verify image appears next to option

### 4. Troubleshooting

#### Poll doesn't appear after creation
- Check browser console for errors
- Verify database tables exist
- Check PHP error logs
- Ensure `create_poll.php` is accessible

#### Can't upload images
- Check `uploads/polls/` directory exists
- Verify directory permissions (755)
- Check file size (max 5MB)
- Verify file type (JPEG, PNG, GIF, WebP)

#### Votes not registering
- Check browser console for errors
- Verify `vote_poll.php` is accessible
- Check database connection
- Verify user is authenticated

#### Poll doesn't end automatically
- Date-based ending requires page refresh or new activity
- Response-based ending happens immediately on vote
- Check `get_poll_data.php` for errors

#### + Button doesn't appear
- Clear browser cache
- Check if `chatActionsBtn` element exists
- Verify JavaScript loaded (check console)
- Check for JavaScript errors

### 5. Browser Compatibility

Tested and working on:
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+
- Mobile browsers (iOS Safari, Chrome Mobile)

### 6. Performance Considerations

- Polls with many options (20+) may load slower
- Large images (>2MB) will take longer to upload
- Many simultaneous voters may cause brief delays
- Consider adding caching for frequently accessed polls

### 7. Security Notes

- All endpoints require authentication
- Poll creators verified before allowing end action
- File uploads validated for type and size
- SQL injection prevented with prepared statements
- XSS prevented with proper escaping

### 8. Known Issues

None at this time. Report issues to development team.

### 9. Success Criteria

✓ Database tables created
✓ + button appears in chat input
✓ Poll modal opens and closes
✓ Polls can be created
✓ Polls appear in chat
✓ Voting works
✓ Vote counts update
✓ Voter list displays
✓ Clear vote works
✓ End poll works (creator only)
✓ Poll ended state displays correctly
✓ Images upload successfully
✓ Date/response ending works
✓ Three dots menu works on polls

### 10. Next Steps After Activation

1. Monitor error logs for issues
2. Gather user feedback
3. Consider adding:
   - Poll templates
   - Anonymous voting
   - Multiple choice polls
   - Poll analytics
   - Export results feature

## Support

If you encounter issues:
1. Check browser console for errors
2. Check PHP error logs
3. Verify database connection
4. Review `POLLS_SETUP.md` for detailed info
5. Check `POLLS_IMPLEMENTATION_SUMMARY.md` for technical details

## Rollback Procedure

If you need to disable polls:

1. Remove database tables:
```sql
DROP TABLE IF EXISTS poll_votes;
DROP TABLE IF EXISTS poll_options;
DROP TABLE IF EXISTS polls;
```

2. Comment out or remove the + button HTML in `app.php`:
```html
<!-- Comment out the chat-input-actions-dropdown div -->
```

3. Clear browser cache

The feature is now fully deactivated.
