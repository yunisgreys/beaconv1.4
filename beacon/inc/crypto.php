<?php

function crypto_key(): string {
    $key = base64_decode((string)config('encryption_key'), true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('config encryption_key must be a base64-encoded 32-byte key.');
    }
    return $key;
}

function encrypt_text(string $plain): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, crypto_key()));
}

function decrypt_text(string $stored): string {
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('Encrypted value is malformed.');
    }
    $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain  = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, crypto_key());
    if ($plain === false) {
        throw new RuntimeException('Could not decrypt value (wrong key or tampered data).');
    }
    return $plain;
}
