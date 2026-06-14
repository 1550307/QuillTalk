# Message Translation Setup

This app now uses the free MyMemory translation API for message translation.

## What changed

- No paid API key is required.
- No translation host needs to be configured.
- The app now auto-detects the source language in QuillTalk, then lets you choose the target language.

## Free limits

According to MyMemory's official docs:

- Anonymous usage: 5,000 chars/day
- With a contact email (`de` parameter): 50,000 chars/day

Official docs:

- https://mymemory.translated.net/doc/index.php
- https://mymemory.translated.net/doc/spec.php
- https://mymemory.translated.net/doc/usagelimits.php

## Optional higher free quota

To raise the free quota from 5,000 to 50,000 chars/day, configure a contact email:

### Apache / `.htaccess`

```apache
SetEnv MYMEMORY_EMAIL "you@example.com"
```

### Server environment

```bash
export MYMEMORY_EMAIL="you@example.com"
```

`translate_message.php` also accepts:

- `MYMEMORY_CONTACT_EMAIL`

## How it works in the app

1. Open a message.
2. Click the message menu.
3. Choose `Translate`.
4. Review the detected source language.
5. Pick the language you want to translate into.

## Limits to know about

- MyMemory's API expects the source language explicitly, so QuillTalk detects it before sending the request.
- MyMemory's official API docs say `q` has a max length of 500 bytes per request.

## Troubleshooting

- `Pick different source and target languages`
  Choose two different languages.

- `MyMemory supports up to 500 bytes per translation request`
  The message is too long for one MyMemory request.

- `MyMemory says this server has used its free translation quota for today`
  Wait for the daily reset, or configure `MYMEMORY_EMAIL` for the larger free quota.

- `MyMemory could not translate this message right now`
  The provider responded with an error or did not return a translation.
