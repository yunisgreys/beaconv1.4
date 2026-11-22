<?php
require __DIR__ . '/inc/bootstrap.php';
$currentUser = current_user();
$isLoggedIn = $currentUser !== null;
$school = config('school_name') ?? 'Christ Life Academy';
?>
<!DOCTYPE html>
<html lang="en" class="landing-html">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BEACON · <?= e($school) ?></title>
    <link rel="icon" href="assets/img/tab-logo.svg">
    <link rel="stylesheet" href="./index.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=DM+Sans:wght@400;500;600;700&display=swap">
    <style>
        html { scroll-behavior: smooth; scroll-padding-top: 76px; }
                /* v1.4: index.css locks html/body for the portal; the landing page must scroll */
        html.landing-html { height: auto; min-height: 100%; width: 100%; overflow-x: hidden; overflow-y: auto; }
        body.landing { margin: 0; display: block; width: 100%; height: auto; min-height: 100vh; overflow: visible; background: #f8fafc; color: #0f172a; font-family: 'DM Sans', system-ui, sans-serif; }

        /* Top navigation */
        .landing-nav {
            position: sticky; top: 0; z-index: 20;
            display: flex; justify-content: space-between; align-items: center; gap: 16px;
            padding: 14px 48px; background: rgba(255,255,255,.94);
            border-bottom: 1px solid var(--border, #e2e8f0);
            backdrop-filter: saturate(1.4) blur(8px); -webkit-backdrop-filter: saturate(1.4) blur(8px);
        }
        .landing-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; min-width: 0; }
        .landing-brand img { height: 26px; width: auto; display: block; }
        .landing-brand-school { font-size: .66rem; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: #1948A5; white-space: nowrap; padding-left: 12px; border-left: 1px solid #e2e8f0; }
        .landing-links { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
        .landing-link { color: #334155; font-weight: 600; font-size: .875rem; text-decoration: none; padding: 9px 14px; border-radius: 10px; }
        .landing-link:hover { background: #f1f5f9; color: #0f172a; }
        .landing-links .btn { font-size: .8125rem; padding: 9px 16px; white-space: nowrap; }

        /* Hero: BEACON + school name only */
        .landing-hero {
            position: relative; overflow: hidden;
            min-height: calc(100vh - 61px); min-height: calc(100svh - 61px);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 64px 24px 96px; text-align: center; color: #fff;
            background: linear-gradient(180deg, #082B70 0%, #5B84EB 38%, #1948A5 92%, #082B70 100%);
        }
        .landing-hero::before, .landing-hero::after { content: ''; position: absolute; border-radius: 50%; border: 1px solid rgba(255,255,255,.12); pointer-events: none; }
        .landing-hero::before { width: 520px; height: 520px; left: -180px; top: -160px; box-shadow: 0 0 0 90px rgba(255,255,255,.025); }
        .landing-hero::after  { width: 420px; height: 420px; right: -150px; bottom: -190px; }
        .landing-hero h1 { margin: 0; position: relative; z-index: 1; }
        .landing-hero h1 img { display: block; width: min(640px, 82vw); height: auto; margin: 0 auto; }
        .landing-school {
            position: relative; z-index: 1; margin: 28px 0 0;
            font-family: 'Manrope', sans-serif; font-weight: 800;
            font-size: clamp(1rem, 2.6vw, 1.6rem); letter-spacing: .32em; text-indent: .32em;
            text-transform: uppercase; color: rgba(255,255,255,.92);
        }
        .landing-scroll {
            position: absolute; z-index: 1; bottom: 28px; left: 50%; transform: translateX(-50%);
            display: inline-flex; flex-direction: column; align-items: center; gap: 6px;
            color: rgba(255,255,255,.75); text-decoration: none; font-size: .75rem; font-weight: 600; letter-spacing: .08em; text-transform: uppercase;
        }
        .landing-scroll:hover { color: #fff; }
        .landing-scroll svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; animation: nudge 1.8s ease-in-out infinite; }
        @keyframes nudge { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(4px); } }

        /* About Us */
        .about { max-width: 1080px; margin: 0 auto; padding: 88px 24px 96px; }
        .about-kicker { font-size: .72rem; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: #1948A5; margin: 0 0 10px; }
        .about h2 { font-family: 'Manrope', sans-serif; font-size: clamp(1.7rem, 3.4vw, 2.3rem); font-weight: 800; letter-spacing: -.02em; line-height: 1.2; margin: 0 0 16px; color: #0f172a; }
        .about-lead { max-width: 720px; font-size: 1.02rem; line-height: 1.7; color: #475569; margin: 0 0 44px; }
        .about-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
        .about-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 24px; box-shadow: 0 1px 2px rgba(15,23,42,.03); }
        .about-card .ico-box { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-bottom: 16px; }
        .about-card .ico-box svg { width: 21px; height: 21px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .about-card h3 { font-family: 'Manrope', sans-serif; font-size: 1rem; font-weight: 800; margin: 0 0 8px; color: #0f172a; }
        .about-card p { font-size: .88rem; line-height: 1.6; color: #64748b; margin: 0; }
        .tone-blue { background: #eff6ff; color: #1d4ed8; } .tone-purple { background: #f5f3ff; color: #7c3aed; }
        .tone-red { background: #fef2f2; color: #dc2626; } .tone-green { background: #f0fdf4; color: #16a34a; }
        .tone-amber { background: #fffbeb; color: #d97706; } .tone-slate { background: #f1f5f9; color: #334155; }

        .about-privacy { margin-top: 44px; display: grid; grid-template-columns: 1.1fr 1fr; gap: 32px; align-items: center; background: #0f1d44; color: #e2e8f0; border-radius: 20px; padding: 36px 40px; }
        .about-privacy h3 { font-family: 'Manrope', sans-serif; font-size: 1.35rem; font-weight: 800; color: #fff; margin: 0 0 10px; }
        .about-privacy p { margin: 0; line-height: 1.65; font-size: .92rem; color: #cbd5e1; }
        .about-privacy ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
        .about-privacy li { display: flex; gap: 10px; align-items: flex-start; font-size: .88rem; line-height: 1.5; }
        .about-privacy li svg { flex-shrink: 0; width: 18px; height: 18px; margin-top: 1px; fill: none; stroke: #7dd3fc; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        .landing-foot { text-align: center; padding: 24px; border-top: 1px solid #e2e8f0; color: #64748b; font-size: .78rem; background: #fff; }

        @media (max-width: 900px) {
            .about-grid { grid-template-columns: 1fr 1fr; }
            .about-privacy { grid-template-columns: 1fr; padding: 28px 24px; }
        }
        @media (max-width: 640px) {
            .landing-nav { padding: 12px 16px; }
            .landing-brand-school { display: none; }
            .landing-link { padding: 8px 10px; }
            .about { padding: 64px 18px 72px; }
            .about-grid { grid-template-columns: 1fr; }
            .landing-school { letter-spacing: .22em; text-indent: .22em; }
            .landing-hero { padding: 48px 18px 88px; }
        }
        @media (max-width: 380px) {
            .landing-brand img { height: 22px; }
            .landing-links { gap: 4px; }
            .landing-link { padding: 8px; font-size: .8rem; }
            .landing-links .btn { padding: 8px 12px; }
        }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } .landing-scroll svg { animation: none; } }
    </style>
</head>
<body class="landing">

<header class="landing-nav">
    <a href="index.php" class="landing-brand" aria-label="BEACON home">
        <img src="assets/img/logo-wordmark.svg" alt="BEACON">
        <span class="landing-brand-school"><?= e($school) ?></span>
    </a>
    <nav class="landing-links" aria-label="Main">
        <a class="landing-link" href="#about">About Us</a>
        <?php if ($isLoggedIn): ?>
            <a class="btn btn-primary" href="<?= e(home_for($currentUser)) ?>">Go to my portal</a>
        <?php else: ?>
            <a class="btn btn-primary" href="login.php">Log in</a>
        <?php endif; ?>
    </nav>
</header>

<main>
    <section class="landing-hero" aria-labelledby="heroTitle">
        <h1 id="heroTitle"><img src="assets/img/logo-wordmark-white-shadow.svg" alt="BEACON"></h1>
        <p class="landing-school"><?= e(mb_strtoupper($school, 'UTF-8')) ?></p>
        <a class="landing-scroll" href="#about">
            About Us
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
        </a>
    </section>

    <section class="about" id="about" aria-labelledby="aboutTitle">
        <p class="about-kicker">About Us</p>
        <h2 id="aboutTitle">Every learner, known and cared for.</h2>
        <p class="about-lead">
            BEACON is the student well-being monitoring system of <?= e($school) ?>. It brings class advisers, the
            Guidance Office, the school clinic, the Registrar and families together in one secure place, so that a
            child's attendance, health and well-being are noticed early and followed up by the right people.
        </p>

        <div class="about-grid">
            <article class="about-card">
                <div class="ico-box tone-blue"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg></div>
                <h3>Class advisers</h3>
                <p>Record attendance, log conduct and physical-state concerns for their advisory class, and send students to the clinic with a digital pass.</p>
            </article>
            <article class="about-card">
                <div class="ico-box tone-purple"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6l7-3z"/></svg></div>
                <h3>Guidance Office</h3>
                <p>Reviews referrals, manages cases and intervention plans, and keeps counselling notes confidential.</p>
            </article>
            <article class="about-card">
                <div class="ico-box tone-red"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/></svg></div>
                <h3>School clinic</h3>
                <p>Checks students in, records time in and out and any medication given, and keeps each learner's health information up to date.</p>
            </article>
            <article class="about-card">
                <div class="ico-box tone-green"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M21.5 20a6.5 6.5 0 0 0-4-6"/></svg></div>
                <h3>Families</h3>
                <p>Parents and guardians see school notices, clinic visits and the updates the school has chosen to share about their child.</p>
            </article>
            <article class="about-card">
                <div class="ico-box tone-amber"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 7h6M9 11h6M9 15h4"/></svg></div>
                <h3>Registrar</h3>
                <p>Keeps student and teacher information accurate and assigns teachers and students to their sections.</p>
            </article>
            <article class="about-card">
                <div class="ico-box tone-slate"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="M10.8 12.2L20 3M16 7l3 3M14 9l2 2"/></svg></div>
                <h3>Administration</h3>
                <p>The Super Admin manages accounts, approves family registrations and reviews the activity log.</p>
            </article>
        </div>

        <div class="about-privacy">
            <div>
                <h3>Private by design</h3>
                <p>Student well-being information is sensitive. BEACON shows each person only what their role needs, and nothing more.</p>
            </div>
            <ul>
                <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12l5 5L20 7"/></svg>Counselling notes are encrypted and are never shown to families.</li>
                <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12l5 5L20 7"/></svg>Mental-health matters are shared with parents only with the student's consent.</li>
                <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12l5 5L20 7"/></svg>Every sign-in and every look at a student's file is recorded in the activity log.</li>
            </ul>
        </div>
    </section>
</main>

<footer class="landing-foot">
    &copy; <?= date('Y') ?> <?= e($school) ?> · BEACON Student Welfare System
</footer>

</body>
</html>
