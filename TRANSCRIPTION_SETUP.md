# Voice Message Transcription Setup

This application uses AssemblyAI for voice message transcription (FREE tier available).

## Setup Instructions

### 1. Get a FREE AssemblyAI.
 API Key

1. Go to https://www.assemblyai.com/
2. Click "Get Started Free" or "Sign Up"
3. Create your account (no credit card required)
4. Navigate to your dashboard
5. Copy your API key from the dashboard

**Free Tier Includes:**
- 5 hours of transcription per month
- No credit card required
- Full API access

### 2. Configure the API Key

You have several options to set the API key:

#### Option A: Environment Variable (Recommended)

Add to your server environment or `.htaccess`:

```apache
SetEnv ASSEMBLYAI_API_KEY "your-api-key-here"
```

Or in your PHP configuration:

```bash
export ASSEMBLYAI_API_KEY="your-api-key-here"
```

#### Option B: PHP Configuration File

Create a file `includes/config.php` (if it doesn't exist) and add:

```php
<?php
putenv('ASSEMBLYAI_API_KEY=your-api-key-here');
```

Then include it at the top of `transcribe_voice.php`.

#### Option C: Direct in Code (Not Recommended for Production)

Edit `transcribe_voice.php` and replace:

```php
$assemblyaiApiKey = getenv('ASSEMBLYAI_API_KEY') ?: '';
```

With:

```php
$assemblyaiApiKey = 'your-api-key-here';
```

**Warning:** Never commit API keys to version control!

### 3. Test the Transcription

1. Send a voice message in the app
2. Click the three dots menu on the voice message
3. Select "Transcribe"
4. Wait a few seconds for the transcription to appear

### 4. Pricing

AssemblyAI pricing:
- **FREE Tier**: 5 hours per month (perfect for personal use)
- **Pay-as-you-go**: $0.00025 per second ($0.015 per minute) after free tier

A typical 30-second voice message costs approximately $0.0075 (less than 1 cent).

### 5. How It Works

The transcription process:
1. Audio file is uploaded to AssemblyAI
2. Transcription job is created
3. System polls for completion (usually 1-5 seconds)
4. Transcribed text is returned and displayed

### 6. Troubleshooting

If transcription doesn't work:

1. Check the error log: `htdocs/transcribe_debug.log`
2. Verify your API key is valid and active
3. Ensure the audio file exists and is accessible
4. Check that cURL is enabled in PHP
5. Verify you haven't exceeded your free tier limit (5 hours/month)
6. Check AssemblyAI dashboard for usage and status

### 7. Alternative Services

If you prefer a different service, you can modify `transcribe_voice.php` to use:

- **OpenAI Whisper**: $0.006/minute (requires payment)
- **Google Cloud Speech-to-Text**: Free tier available
- **Azure Speech Services**: Free tier available
- **Deepgram**: Free tier available

### 8. Fallback Behavior

If no API key is configured, the transcription feature will show:
"[Transcription service not configured. Please set ASSEMBLYAI_API_KEY environment variable to enable speech-to-text. Get a free API key at https://www.assemblyai.com/]"

This allows the app to function without transcription until you're ready to set it up.
