<?php
// includes/webauthn_config.php

// Rely on your existing DB include to provide $pdo
// Add domain/origin info for your site:
$webauthn_origin = 'https://quilltalk.org'; // MUST match site origin (https)
$webauthn_rp_id  = 'quilltalk.org';        // RP ID (your domain)
$webauthn_rp_name = 'QuillTalk';

// How long challenges remain valid (ms)
$webauthn_timeout = 60000;

// Whether to require resident keys (we'll NOT require)
$webauthn_require_resident_key = false;

// Attestation: opt to 'none' to keep things simple for user devices
$webauthn_attestation = 'none'; // or 'direct' if you want attestations

// For the library we need to register some supported algorithms. The default ES256 is fine.
