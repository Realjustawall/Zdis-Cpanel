# ZDIS

ZDIS is a self-hosted communication platform for communities, creators, and teams. It brings messaging, community spaces, voice and video calls, moderation, bots, and administration together in one web interface.

This edition runs on PHP and MySQL/MariaDB and is designed for cPanel hosting. It uses the original ZDIS React interface with support for English, Persian, and right-to-left layouts.

## Messaging

- Direct messages and group conversations.
- Community text channels organized into categories.
- Message replies, editing, deletion, and reactions.
- Pinned messages and personal saved messages.
- Message search, unread counts, and read tracking.
- Scheduled messages and automatic message expiration.
- Drafts for conversations that do not require encrypted content.
- Encrypted direct messages with signed message envelopes.
- In-app notifications and notification preferences.

## Community Spaces

- Groups with owners, administrators, moderators, and members.
- Public and private channels.
- Invitations with expiration dates and usage limits.
- Member profiles, nicknames, avatars, and presence indicators.
- Group icons, descriptions, and discovery settings.
- Friends, friend requests, and user blocking.
- Linked social accounts and creator network features.
- Community events with interested and going RSVP responses.
- Community boosts and supporter information.
- Subscription tier records and subscription management.
- Community activity insights.
- Announcement publishing and channel following.

## Forums and Polls

- Forum posts with titles, tags, and replies.
- Private threads with controlled membership.
- Thread locking and archiving.
- Single-choice and multiple-choice polls.
- Anonymous voting and scheduled poll closing.
- Exam-style polls with correct-answer configuration.
- Poll analytics for the poll creator.

## Voice and Video

- Voice channels and direct voice calls.
- Direct video calls with incoming-call notifications.
- Browser-based WebRTC media connections.
- Microphone, camera, mute, and participant state controls.
- Mesh calls for up to eight participants.

## Files and Media

- Local file uploads and protected downloads.
- Image, video, audio, and document attachments.
- Resumable uploads for interrupted transfers.
- Voice-message uploads.
- File size and media-category restrictions.
- Access checks for private-channel and private-thread attachments.
- HTTP Range support for media playback.
- Profile avatars, group artwork, and role icons.

## Roles and Moderation

- Custom group roles with colors, icons, and permission lists.
- Role hierarchy and controlled role assignment.
- Group, channel, category, role, and member permission rules.
- Category permission synchronization.
- Private-channel visibility controls.
- Member timeouts, kicks, bans, and channel slowmode.
- Account-level restrictions for messaging and media access.
- Optional two-factor authentication requirements for group moderation.
- Keyword, spam, mention, link, and invite filtering.
- Moderation reports and status updates.
- Moderation cases and action history.

## Built-in Bots

### Welcomer

- Custom welcome and goodbye messages.
- Message templates with member and community details.
- PNG welcome banners with configurable colors and backgrounds.
- Automatic role assignment when members join.

### Moderator

- Configurable anti-spam and anti-raid rules.
- Moderation actions, warnings, timeouts, and temporary restrictions.
- Protection rules for selected users and roles.
- Text and voice XP, levels, leaderboards, and role rewards.
- Credits, daily rewards, transfers, reputation, and profile titles.
- Self-selectable roles.
- Private support tickets with claim, close, reopen, and transcript actions.
- Configurable automatic responses and embed messages.
- Temporary voice channels and invitations.
- A reaction-based starboard.

## Integrations

- Scoped API keys with expiration and revocation.
- OAuth application registration and authorization-code exchange.
- Bot accounts, tokens, and community installation controls.
- Registered application commands with configurable responses.
- Incoming webhooks for posting messages.
- Signed outgoing webhooks with queued delivery and retries.
- Access-controlled synchronization events.
- Text-message export and import.

## Administration and Account Security

- A dedicated administration panel.
- User creation, profile management, and account activation controls.
- Administrator password resets and optional forced password changes.
- Personal password changes with current-password verification.
- Session listing, sign-out, and revocation of other sessions.
- Two-factor authentication with one-time recovery codes.
- Public registration controls and application settings.
- Group administration and discovery approval.
- Account access policies and upload limits.
- Usage statistics, activity analytics, and audit records.

## First-Run Installer

- Automatic setup guidance on the first visit.
- PHP extension and filesystem permission checks.
- Database host, port, name, username, and password fields.
- A real MySQL connection test before installation.
- Protection against installing over a nonempty database.
- Administrator username, display name, email, and password setup.
- Automatic configuration-file and security-key generation.
- Private configuration storage outside the public web directory.
- Installation locking after setup completes.

## Interface

- Responsive layouts for desktop and mobile browsers.
- English and Persian language support.
- Right-to-left presentation.
- Theme selection and appearance controls.
- Progressive Web App support and an offline fallback page.
