-- Optional: Add foreign key constraints to call_invites table
-- Only run this after the tables are created and you've verified the users table structure
-- This provides referential integrity but is not required for the feature to work

-- Add foreign keys to call_invites
ALTER TABLE call_invites
ADD CONSTRAINT fk_call_invites_inviter 
    FOREIGN KEY (inviter_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE call_invites
ADD CONSTRAINT fk_call_invites_invited 
    FOREIGN KEY (invited_user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Add foreign keys to call_invite_rejections
ALTER TABLE call_invite_rejections
ADD CONSTRAINT fk_call_rejections_inviter 
    FOREIGN KEY (inviter_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE call_invite_rejections
ADD CONSTRAINT fk_call_rejections_rejected_by 
    FOREIGN KEY (rejected_by_user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE call_invite_rejections
ADD CONSTRAINT fk_call_rejections_invite 
    FOREIGN KEY (invite_id) REFERENCES call_invites(id) ON DELETE CASCADE;
