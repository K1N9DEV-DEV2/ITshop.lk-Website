<?php
/*
 * ─────────────────────────────────────────────────────────────
 *  AD POPUP SNIPPET  –  include this in your index.php
 *  PLACEMENT: right after include 'header.php';
 *
 *  Images are managed via the Admin Panel → Media → Popup Images
 * ─────────────────────────────────────────────────────────────
 */

// Load active popup images from DB
$adImages = [];
if (!empty($pdo)) {
    try {
        $stmt = $pdo->query("SELECT image_src, alt_text, link_url FROM popup_images WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
        $adImages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* silently skip */ }
}

// Fallback: if DB is unavailable or no images, show nothing
if (empty($adImages)) return;
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

/* ── Carousel wrapper ─────────────────────────────────── */
.ad-carousel {
    position: relative;
    width: 100%;
    overflow: hidden;
}
.ad-carousel-track {
    display: flex;
    transition: transform 0.45s cubic-bezier(0.4, 0, 0.2, 1);
    will-change: transform;
}
.ad-carousel-track .ad-post-img {
    display: block;
    width: 100%;
    flex-shrink: 0;
    height: auto;
    pointer-events: none;
    user-select: none;
}
.ad-carousel-track a.ad-slide-link {
    display: block;
    flex-shrink: 0;
    width: 100%;
}
.ad-carousel-track a.ad-slide-link .ad-post-img {
    pointer-events: auto;
}

/* ── Dot indicators ───────────────────────────────────── */
.ad-dots {
    position: absolute;
    bottom: 10px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 6px;
    z-index: 8;
}
.ad-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: rgba(255,255,255,0.45);
    border: none;
    cursor: pointer;
    padding: 0;
    transition: background 0.2s ease, transform 0.2s ease;
}
.ad-dot.active {
    background: #fff;
    transform: scale(1.3);
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
    animation: popIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1) 0.9s both;
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
}
</style>

<!-- ══ POPUP HTML ══════════════════════════════════════════ -->
<div id="ad-popup-overlay" role="dialog" aria-modal="true" aria-label="Advertisement">
    <div id="ad-popup">

        <!-- ✕ Close button -->
        <button id="ad-close-btn" aria-label="Close advertisement">
            <i class="fas fa-times"></i>
        </button>

        <!-- ── Image Carousel ── -->
        <div class="ad-carousel">
            <div class="ad-carousel-track" id="ad-track">
                <?php foreach ($adImages as $img):
                    $has_link = !empty($img['link_url']);
                ?>
                <?php if ($has_link): ?>
                <a class="ad-slide-link" href="<?= htmlspecialchars($img['link_url']) ?>">
                    <img
                        class="ad-post-img"
                        src="<?= htmlspecialchars($img['image_src']) ?>"
                        alt="<?= htmlspecialchars($img['alt_text'] ?? '') ?>"
                        loading="lazy"
                    />
                </a>
                <?php else: ?>
                <img
                    class="ad-post-img"
                    src="<?= htmlspecialchars($img['image_src']) ?>"
                    alt="<?= htmlspecialchars($img['alt_text'] ?? '') ?>"
                    loading="lazy"
                />
                <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <!-- Dot indicators (only if more than one image) -->
            <?php if (count($adImages) > 1): ?>
            <div class="ad-dots" id="ad-dots">
                <?php foreach ($adImages as $i => $img): ?>
                <button
                    class="ad-dot <?= $i === 0 ? 'active' : '' ?>"
                    data-index="<?= $i ?>"
                    aria-label="Go to image <?= $i + 1 ?>"
                ></button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
(function () {
    const overlay  = document.getElementById('ad-popup-overlay');
    const closeBtn = document.getElementById('ad-close-btn');
    const track    = document.getElementById('ad-track');
    const dots     = document.querySelectorAll('.ad-dot');
    const DELAY      = 800;   // ms after page load before showing
    const AUTO_DELAY = 4000;  // ms between auto-advances

    const total = dots.length || 0;
    let current = 0;
    let autoTimer;

    /* ── Show popup ── */
    function showPopup() {
        overlay.classList.add('show');
        document.body.style.overflow = 'hidden';
        if (total > 1) startAuto();
    }

    /* ── Close popup ── */
    function closePopup() {
        clearInterval(autoTimer);
        overlay.classList.add('hide');
        document.body.style.overflow = '';
        overlay.addEventListener('transitionend', () => overlay.remove(), { once: true });
    }

    /* ── Go to slide ── */
    function goTo(index) {
        current = (index + total) % total;
        track.style.transform = `translateX(-${current * 100}%)`;
        dots.forEach((d, i) => d.classList.toggle('active', i === current));
    }

    /* ── Auto-advance ── */
    function startAuto() {
        autoTimer = setInterval(() => goTo(current + 1), AUTO_DELAY);
    }
    function resetAuto() {
        clearInterval(autoTimer);
        startAuto();
    }

    /* ── Dot buttons ── */
    dots.forEach(dot => dot.addEventListener('click', () => {
        goTo(parseInt(dot.dataset.index));
        resetAuto();
    }));

    /* ── Touch / swipe support ── */
    let touchStartX = 0;
    track.addEventListener('touchstart', e => { touchStartX = e.touches[0].clientX; }, { passive: true });
    track.addEventListener('touchend', e => {
        const diff = touchStartX - e.changedTouches[0].clientX;
        if (Math.abs(diff) > 40) {
            goTo(diff > 0 ? current + 1 : current - 1);
            resetAuto();
        }
    }, { passive: true });

    /* ── Event listeners ── */
    closeBtn.addEventListener('click', closePopup);
    overlay.addEventListener('click', e => { if (e.target === overlay) closePopup(); });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closePopup();
    });

    /* ── Fire after page loads ── */
    window.addEventListener('load', () => setTimeout(showPopup, DELAY));
})();
</script>