<?php
/*
 * BEACON · Supabase connection settings.
 *
 * BEACON's PHP connects straight to your Supabase PostgreSQL database
 * (server-side, through pdo_pgsql). The browser never sees these values.
 *
 * WHERE TO FIND THE CONNECTION STRING
 *   Supabase dashboard → your project → Connect (top bar) → "Session pooler"
 *   → copy the URI. It looks like:
 *     postgresql://postgres.abcdefghijklmnop:[YOUR-PASSWORD]@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres
 *   Replace [YOUR-PASSWORD] with your database password (Project Settings →
 *   Database → Reset database password if you don't have it).
 *
 *   Use the SESSION pooler (port 5432). The "Direct connection" host
 *   (db.xxxx.supabase.co) needs IPv6, which most home and school networks
 *   in the Philippines don't have, and the Transaction pooler (port 6543)
 *   doesn't support the prepared statements BEACON uses.
 *   If your password contains @ : / ? # or %, URL-encode it (e.g. @ → %40).
 *
 * ENVIRONMENT VARIABLES WIN
 *   If the server has DATABASE_URL (or SUPABASE_DB_URL) set, that is used
 *   instead of the value written below. Same for SUPABASE_URL,
 *   SUPABASE_ANON_KEY and SUPABASE_SERVICE_ROLE_KEY.
 *
 * KEEP THIS FILE PRIVATE: it holds your database password. Do not push it
 * to a public GitHub repository or share it in screenshots.
 */

$env = static function (string ...$names): ?string {
    foreach ($names as $name) {
        $v = getenv($name);
        if ($v === false || $v === '') $v = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        if (is_string($v) && trim($v) !== '') return trim($v);
    }
    return null;
};

return [
    // The only value BEACON needs. Paste your Session pooler URI here.
    'database_url' => $env('DATABASE_URL', 'SUPABASE_DB_URL')
        ?? 'postgresql://postgres.xlwgzpjxdqsqwtnjtzgf:[3erenity3wan]@aws-0-ap-southeast-2.pooler.supabase.com:5432/postgres',

    // Optional: project URL and API keys (Project Settings → API).
    // BEACON doesn't need them today: every table is closed to the REST API
    // on purpose. Never print the service role key to the browser.
    'url'              => $env('SUPABASE_URL') ?? '',
    'anon_key'         => $env('SUPABASE_ANON_KEY') ?? '',
    'service_role_key' => $env('SUPABASE_SERVICE_ROLE_KEY') ?? '',

    // Connection options.
    'sslmode'          => 'require',      // Supabase requires TLS
    'connect_timeout'  => 10,             // seconds
    'timezone'         => 'Asia/Manila',  // times are stored as Philippine local time
];
