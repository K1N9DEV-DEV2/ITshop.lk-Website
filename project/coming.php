<?php
$page_title = 'Coming Soon – IT Shop.LK';
// include 'header.php'; // Uncomment when integrating
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --ink: #0a0a0f;
            --ink-soft: #3d3d50;
            --ink-muted: #8888a0;
            --accent: #0cb100;
            --accent-glow: rgba(12, 177, 0, 0.25);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Red Hat Display', sans-serif;
            background: var(--ink);
            color: #fff;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            -webkit-font-smoothing: antialiased;
        }

        /* ── BACKGROUND ── */
        .bg-grid {
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
            background-size: 60px 60px;
            pointer-events: none;
            z-index: 0;
        }

        .blob-1, .blob-2 {
            position: fixed;
            border-radius: 50%;
            filter: blur(100px);
            pointer-events: none;
            z-index: 0;
        }
        .blob-1 {
            width: 700px; height: 700px;
            background: radial-gradient(circle, rgba(12, 177, 0, 0.28) 0%, transparent 70%);
            top: -200px; right: -150px;
            animation: blobDrift 12s ease-in-out infinite alternate;
        }
        .blob-2 {
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(79, 135, 188, 0.18) 0%, transparent 70%);
            bottom: -100px; left: -100px;
            animation: blobDrift 15s 2s ease-in-out infinite alternate-reverse;
        }

        @keyframes blobDrift {
            from { transform: translate(0, 0) scale(1); }
            to   { transform: translate(40px, 30px) scale(1.08); }
        }

        /* ── CONTENT ── */
        .container {
            position: relative;
            z-index: 2;
            text-align: center;
            padding: 2rem;
            max-width: 680px;
            width: 100%;
        }

        /* Logo / Brand */
        .brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 3rem;
            animation: fadeDown 0.6s ease both;
        }
        .brand-icon {
            width: 44px; height: 44px;
            background: var(--accent);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            box-shadow: 0 4px 20px var(--accent-glow);
        }
        .brand-name {
            font-weight: 800;
            font-size: 1.2rem;
            color: #fff;
            letter-spacing: -0.02em;
        }
        .brand-name span { color: var(--accent); }

        /* Badge */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(12, 177, 0, 0.12);
            border: 1px solid rgba(12, 177, 0, 0.35);
            color: #c2ffc2;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            padding: 6px 16px;
            border-radius: 100px;
            margin-bottom: 1.75rem;
            animation: fadeDown 0.6s 0.1s ease both;
        }
        .badge-dot {
            width: 6px; height: 6px;
            background: var(--accent);
            border-radius: 50%;
            animation: pulse 1.8s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%       { opacity: 0.5; transform: scale(0.7); }
        }

        /* Heading */
        h1 {
            font-size: clamp(3rem, 9vw, 5.5rem);
            font-weight: 900;
            line-height: 1.0;
            letter-spacing: -0.04em;
            color: #fff;
            margin-bottom: 1.5rem;
            animation: fadeDown 0.6s 0.15s ease both;
        }
        h1 em {
            font-style: normal;
            background: linear-gradient(135deg, #1eff00 0%, #3dbfff 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .subtext {
            font-size: 1.05rem;
            color: #9999b8;
            line-height: 1.75;
            max-width: 480px;
            margin: 0 auto 3rem;
            animation: fadeDown 0.6s 0.2s ease both;
        }

        /* ── COUNTDOWN ── */
        .countdown {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-bottom: 3rem;
            flex-wrap: wrap;
            animation: fadeDown 0.6s 0.25s ease both;
        }
        .count-box {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 1.25rem 1.5rem;
            min-width: 88px;
            backdrop-filter: blur(8px);
            transition: border-color 0.3s ease;
        }
        .count-box:hover { border-color: rgba(12, 177, 0, 0.4); }
        .count-num {
            font-size: 2.4rem;
            font-weight: 800;
            color: #fff;
            line-height: 1;
            letter-spacing: -0.03em;
        }
        .count-label {
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--ink-muted);
            margin-top: 6px;
        }

        /* ── NOTIFY FORM ── */
        .notify-form {
            display: flex;
            max-width: 420px;
            margin: 0 auto 3rem;
            gap: 10px;
            animation: fadeDown 0.6s 0.3s ease both;
        }
        .notify-input {
            flex: 1;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 12px;
            color: #fff;
            font-family: 'Red Hat Display', sans-serif;
            font-size: 0.9rem;
            padding: 13px 18px;
            outline: none;
            transition: border-color 0.25s ease, background 0.25s ease;
        }
        .notify-input::placeholder { color: #666688; }
        .notify-input:focus {
            border-color: var(--accent);
            background: rgba(255,255,255,0.1);
        }
        .notify-btn {
            background: var(--accent);
            color: #fff;
            font-family: 'Red Hat Display', sans-serif;
            font-weight: 700;
            font-size: 0.9rem;
            padding: 13px 22px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            white-space: nowrap;
            box-shadow: 0 4px 20px var(--accent-glow);
            transition: all 0.25s ease;
        }
        .notify-btn:hover {
            background: #098600;
            transform: translateY(-2px);
            box-shadow: 0 8px 28px var(--accent-glow);
        }
        .notify-success {
            display: none;
            color: #7dff7d;
            font-size: 0.9rem;
            font-weight: 600;
            margin-top: -2rem;
            margin-bottom: 2rem;
            animation: fadeDown 0.4s ease both;
        }
        .notify-success.show { display: block; }

        /* ── SOCIALS ── */
        .socials {
            display: flex;
            justify-content: center;
            gap: 12px;
            animation: fadeDown 0.6s 0.35s ease both;
        }
        .social-link {
            width: 44px; height: 44px;
            border-radius: 50%;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.1);
            color: #9999b8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            text-decoration: none;
            transition: all 0.25s ease;
        }
        .social-link:hover {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
            transform: translateY(-3px);
            box-shadow: 0 6px 16px var(--accent-glow);
        }

        /* ── WhatsApp FAB ── */
        .wa-btn {
            position: fixed;
            bottom: 24px; right: 24px;
            width: 56px; height: 56px;
            background: #25d366;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            z-index: 9999;
            text-decoration: none;
            box-shadow: 0 6px 20px rgba(37,211,102,0.45);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }
        .wa-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 10px 30px rgba(37,211,102,0.55);
            color: white;
        }

        /* ── ANIMATIONS ── */
        @keyframes fadeDown {
            from { opacity: 0; transform: translateY(-18px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── RESPONSIVE ── */
        @media (max-width: 480px) {
            .notify-form { flex-direction: column; }
            .count-box { min-width: 70px; padding: 1rem 1.1rem; }
            .count-num { font-size: 2rem; }
        }
    </style>
</head>
<body>

<div class="bg-grid"></div>
<div class="blob-1"></div>
<div class="blob-2"></div>

<div class="container">

    

    <!-- Badge -->
    <div class="badge"><span class="badge-dot"></span> Something Big Is Coming</div>

    <!-- Heading -->
    <h1>We're Almost <em>Ready</em></h1>

    <p class="subtext">
       coming soon to ITshop.lk Stay tuned!
    </p>


    <!-- Socials -->
    <div class="socials">
        <a href="#" class="social-link" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
        <a href="#" class="social-link" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
        <a href="#" class="social-link" aria-label="WhatsApp"><i class="fab fa-whatsapp"></i></a>
        <a href="#" class="social-link" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
    </div>

</div>



<script>
    // ── COUNTDOWN ──────────────────────────────────────────
    // Set your launch date here
    const launchDate = new Date('2025-09-01T00:00:00').getTime();

    function updateCountdown() {
        const now  = new Date().getTime();
        const diff = launchDate - now;

        if (diff <= 0) {
            document.getElementById('days').textContent  = '00';
            document.getElementById('hours').textContent = '00';
            document.getElementById('mins').textContent  = '00';
            document.getElementById('secs').textContent  = '00';
            return;
        }

        const pad = n => String(Math.floor(n)).padStart(2, '0');

        document.getElementById('days').textContent  = pad(diff / (1000 * 60 * 60 * 24));
        document.getElementById('hours').textContent = pad((diff / (1000 * 60 * 60)) % 24);
        document.getElementById('mins').textContent  = pad((diff / (1000 * 60)) % 60);
        document.getElementById('secs').textContent  = pad((diff / 1000) % 60);
    }

    updateCountdown();
    setInterval(updateCountdown, 1000);

    // ── NOTIFY ─────────────────────────────────────────────
    function handleNotify() {
        const input = document.getElementById('emailInput');
        const success = document.getElementById('successMsg');

        if (!input.value || !input.value.includes('@')) {
            input.style.borderColor = '#ff4f4f';
            setTimeout(() => input.style.borderColor = '', 1500);
            return;
        }

        // TODO: wire up to your backend / email list
        input.parentElement.style.display = 'none';
        success.classList.add('show');
    }

    document.getElementById('emailInput').addEventListener('keydown', e => {
        if (e.key === 'Enter') handleNotify();
    });
</script>
</body>
</html>