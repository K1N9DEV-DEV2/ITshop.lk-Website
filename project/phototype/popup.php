<?php
/*
 * ─────────────────────────────────────────────────────────────
 *  AD POPUP SNIPPET  –  paste this into your index.php
 *  PLACEMENT: right after include 'header.php';
 *
 *  Also copy  2nd_repair_post_3.png  into your project root
 *  (same folder as index.php)
 * ─────────────────────────────────────────────────────────────
 */
?>

<style>
/* ── Overlay backdrop ─────────────────────────────────── */
#ad-popup-overlay {
    position: fixed;
    inset: 0;
    z-index: 99999;
    background: rgba(5, 5, 12, 0.85);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.4s ease, visibility 0.4s ease;
}
#ad-popup-overlay.show {
    opacity: 1;
    visibility: visible;
}

/* ── Popup card ───────────────────────────────────────── */
#ad-popup {
    position: relative;
    width: 100%;
    max-width: 480px;
    border-radius: 20px;
    overflow: hidden;
    background: #fff;
    box-shadow:
        0 0 0 1px rgba(255,255,255,0.15),
        0 40px 100px rgba(0,0,0,0.7);
    transform: translateY(40px) scale(0.94);
    transition: transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
}
#ad-popup-overlay.show #ad-popup {
    transform: translateY(0) scale(1);
}

/* ── The ad image ─────────────────────────────────────── */
#ad-popup .ad-post-img {
    display: block;
    width: 100%;
    height: auto;
    pointer-events: none;
    user-select: none;
}

/* ── Close × button ───────────────────────────────────── */
#ad-close-btn {
    position: absolute;
    top: 12px;
    right: 12px;
    z-index: 10;
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.65);
    border: 2px solid rgba(255, 255, 255, 0.35);
    color: #fff;
    font-size: 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    backdrop-filter: blur(6px);
    transition: background 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
    opacity: 0;
    pointer-events: none;
}
#ad-close-btn.ready {
    opacity: 1;
    pointer-events: auto;
    animation: popIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1) both;
}
@keyframes popIn {
    from { opacity: 0; transform: scale(0.5); }
    to   { opacity: 1; transform: scale(1); }
}
#ad-close-btn:hover {
    background: rgba(220, 38, 38, 0.85);
    border-color: rgba(255,255,255,0.6);
    transform: scale(1.1) rotate(90deg);
}

/* Spinning countdown ring around close button */
#ad-close-btn .countdown-ring {
    position: absolute;
    inset: -3px;
    border-radius: 50%;
    border: 3px solid transparent;
    border-top-color: #0cb100;
    border-right-color: #0cb100;
    animation: ringCountdown 5s linear forwards;
    pointer-events: none;
}
@keyframes ringCountdown {
    0%   { transform: rotate(0deg);   opacity: 1; }
    100% { transform: rotate(360deg); opacity: 0; }
}

/* ── "Close in Xs" label ──────────────────────────────── */
.ad-skip-label {
    position: absolute;
    top: 16px;
    left: 14px;
    background: rgba(0,0,0,0.55);
    color: rgba(255,255,255,0.85);
    font-family: 'Red Hat Display', sans-serif;
    font-size: 0.72rem;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 100px;
    backdrop-filter: blur(4px);
    pointer-events: none;
    transition: opacity 0.3s ease;
}
.ad-skip-label.hidden { opacity: 0; }

/* ── Bottom CTA strip ─────────────────────────────────── */
.ad-strip {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    background: #0cb100;
    color: #fff;
    font-family: 'Red Hat Display', sans-serif;
    font-weight: 700;
    font-size: 0.95rem;
    padding: 13px 20px;
    letter-spacing: 0.02em;
    cursor: pointer;
    text-decoration: none;
    transition: background 0.2s ease;
}
.ad-strip:hover {
    background: #098600;
    color: #fff;
    text-decoration: none;
}
.ad-strip i { font-size: 1rem; }

/* ── Hide / close transition ──────────────────────────── */
#ad-popup-overlay.hide {
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.35s ease;
}
#ad-popup-overlay.hide #ad-popup {
    transform: scale(0.92) translateY(-16px);
    transition: transform 0.35s ease;
}

/* ── Mobile ───────────────────────────────────────────── */
@media (max-width: 520px) {
    #ad-popup { max-width: 95vw; border-radius: 16px; }
    .ad-strip { font-size: 0.85rem; padding: 11px 16px; }
}
</style>

<!-- ══ POPUP HTML ══════════════════════════════════════════ -->
<div id="ad-popup-overlay" role="dialog" aria-modal="true" aria-label="Advertisement">
    <div id="ad-popup">

        <!-- ✕ Close button (unlocks after 5 seconds) -->
        <button id="ad-close-btn" aria-label="Close advertisement">
            <div class="countdown-ring"></div>
            <i class="fas fa-times"></i>
        </button>

        <!-- Countdown label (top-left) -->
        <div class="ad-skip-label" id="ad-skip-label">Close in 5s</div>

        <!-- ★ YOUR FACEBOOK POST IMAGE ★ -->
        <img
            class="ad-post-img"
            src="/uploads/2nd_repair_post_3.png"
            alt="Expert IT Repairs – Rapidventure Sri Lanka"
        />

        <!-- Bottom click-to-call strip -->
        <a href="tel:+94718508203" class="ad-strip">
            <i class="fas fa-phone-alt"></i>
            Contact Us Now &nbsp;·&nbsp; +94 71 850 8203
        </a>

    </div>
</div>

<script>
(function () {
    const overlay   = document.getElementById('ad-popup-overlay');
    const closeBtn  = document.getElementById('ad-close-btn');
    const skipLabel = document.getElementById('ad-skip-label');

    const COUNTDOWN = 5;    // seconds before ✕ unlocks
    const DELAY     = 800;  // ms after page load before showing

    let count = COUNTDOWN;
    let ticker;

    /* Show popup */
    function showPopup() {
        overlay.classList.add('show');
        document.body.style.overflow = 'hidden';
        startCountdown();
    }

    /* Countdown ticker */
    function startCountdown() {
        ticker = setInterval(() => {
            count--;
            if (count > 0) {
                skipLabel.textContent = `Close in ${count}s`;
            } else {
                clearInterval(ticker);
                skipLabel.classList.add('hidden');
                closeBtn.classList.add('ready'); // ✕ appears
            }
        }, 1000);
    }

    /* Close popup */
    function closePopup() {
        if (count > 0) return; // locked during countdown
        clearInterval(ticker);
        overlay.classList.add('hide');
        document.body.style.overflow = '';
        overlay.addEventListener('transitionend', () => overlay.remove(), { once: true });
    }

    closeBtn.addEventListener('click', closePopup);

    // Click backdrop to close (after countdown)
    overlay.addEventListener('click', e => {
        if (e.target === overlay) closePopup();
    });

    // Escape key
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closePopup();
    });

    // Fire after page fully loads
    window.addEventListener('load', () => setTimeout(showPopup, DELAY));
})();
</script>