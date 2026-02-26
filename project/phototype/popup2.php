<?php
/**
 * popup.php — Dynamic popup carousel
 * Reads active popup images from the `popup_images` DB table.
 * Drop this file in your project root (same level as db.php).
 */

// ── DB connection (reuse existing db.php) ─────────────────────────────────────
$pdo_popup = null;
try {
    if (!isset($pdo)) {          // only connect if not already connected by parent file
        require_once __DIR__ . '/db.php';
        $pdo_popup = $pdo;
    } else {
        $pdo_popup = $pdo;
    }
} catch (Throwable $e) {
    $pdo_popup = null;
}

// ── Fetch active popup images ─────────────────────────────────────────────────
$adImages = [];
if ($pdo_popup) {
    try {
        $stmt = $pdo_popup->query(
            "SELECT image_src, alt_text, link_url
             FROM popup_images
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC"
        );
        $adImages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $adImages = [];
    }
}

// ── Only render if we have images ─────────────────────────────────────────────
if (empty($adImages)) return;
?>

<!-- ══ POPUP OVERLAY ═══════════════════════════════════════════════════════════ -->
<div id="adPopup" style="
    display:none; position:fixed; inset:0; z-index:9999;
    background:rgba(0,0,0,0.65); backdrop-filter:blur(6px);
    align-items:center; justify-content:center; padding:1rem;">

    <div style="
        position:relative; background:#fff; border-radius:18px;
        max-width:520px; width:100%; overflow:hidden;
        box-shadow:0 32px 80px rgba(0,0,0,0.4);
        animation:popupIn .3s cubic-bezier(.22,1,.36,1)">

        <!-- Close button -->
        <button onclick="closeAdPopup()" style="
            position:absolute; top:12px; right:12px; z-index:10;
            width:34px; height:34px; border-radius:50%; border:none;
            background:rgba(0,0,0,0.55); color:#fff; font-size:1rem;
            cursor:pointer; display:flex; align-items:center;
            justify-content:center; transition:background .2s;
            backdrop-filter:blur(4px)"
            onmouseover="this.style.background='rgba(0,0,0,0.8)'"
            onmouseout="this.style.background='rgba(0,0,0,0.55)'">
            <i class="fas fa-xmark"></i>
        </button>

        <!-- Carousel wrapper -->
        <div id="popupCarousel" style="position:relative; overflow:hidden;">
            <div id="popupTrack" style="
                display:flex; transition:transform .4s cubic-bezier(.22,1,.36,1);
                will-change:transform;">

                <?php foreach ($adImages as $img): ?>
                <div style="min-width:100%; flex-shrink:0;">
                    <?php if (!empty($img['link_url'])): ?>
                    <a href="<?= htmlspecialchars($img['link_url']) ?>" onclick="closeAdPopup()">
                    <?php endif; ?>

                    <img src="<?= htmlspecialchars($img['image_src']) ?>"
                         alt="<?= htmlspecialchars($img['alt_text'] ?? '') ?>"
                         style="width:100%; display:block; max-height:420px; object-fit:cover;"
                         loading="lazy">

                    <?php if (!empty($img['link_url'])): ?>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

            </div><!-- /track -->

            <?php if (count($adImages) > 1): ?>
            <!-- Prev / Next arrows -->
            <button onclick="popupSlide(-1)" style="
                position:absolute; left:10px; top:50%; transform:translateY(-50%);
                width:36px; height:36px; border-radius:50%; border:none;
                background:rgba(0,0,0,0.5); color:#fff; font-size:.9rem;
                cursor:pointer; backdrop-filter:blur(4px); transition:background .18s;"
                onmouseover="this.style.background='rgba(0,0,0,0.8)'"
                onmouseout="this.style.background='rgba(0,0,0,0.5)'">
                <i class="fas fa-chevron-left"></i>
            </button>
            <button onclick="popupSlide(1)" style="
                position:absolute; right:10px; top:50%; transform:translateY(-50%);
                width:36px; height:36px; border-radius:50%; border:none;
                background:rgba(0,0,0,0.5); color:#fff; font-size:.9rem;
                cursor:pointer; backdrop-filter:blur(4px); transition:background .18s;"
                onmouseover="this.style.background='rgba(0,0,0,0.8)'"
                onmouseout="this.style.background='rgba(0,0,0,0.5)'">
                <i class="fas fa-chevron-right"></i>
            </button>

            <!-- Dot indicators -->
            <div id="popupDots" style="
                position:absolute; bottom:12px; left:50%; transform:translateX(-50%);
                display:flex; gap:6px; align-items:center;">
                <?php foreach ($adImages as $idx => $_): ?>
                <button onclick="popupGoTo(<?= $idx ?>)" style="
                    width:<?= $idx === 0 ? '22' : '8' ?>px; height:8px;
                    border-radius:100px; border:none; padding:0;
                    background:<?= $idx === 0 ? '#fff' : 'rgba(255,255,255,0.45)' ?>;
                    cursor:pointer; transition:all .25s;"
                    id="popupDot<?= $idx ?>"></button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div><!-- /carousel -->

        <!-- Optional bottom bar with "Don't show again" -->
        <div style="
            padding:.65rem 1.1rem; display:flex; align-items:center;
            justify-content:space-between; background:#f8f8fa; font-size:.78rem;
            color:#888; border-top:1px solid #eee;">
            <span>Special offers for you</span>
            <button onclick="dismissPopupForever()" style="
                background:none; border:none; font-size:.76rem; color:#aaa;
                cursor:pointer; text-decoration:underline; font-family:inherit;"
                title="Won't show again on this browser">
                Don't show again
            </button>
        </div>
    </div>
</div>

<style>
@keyframes popupIn {
    from { opacity:0; transform:scale(.93) translateY(12px); }
    to   { opacity:1; transform:scale(1)   translateY(0);    }
}
</style>

<script>
(function () {
    const STORAGE_KEY = 'itshop_popup_dismissed';
    const SESSION_KEY = 'itshop_popup_seen';
    const TOTAL       = <?= count($adImages) ?>;

    let currentSlide = 0;
    let autoTimer    = null;

    // ── Show popup after short delay (unless dismissed) ───────────────────────
    function maybeShow() {
        if (localStorage.getItem(STORAGE_KEY)) return;     // user clicked "don't show"
        if (sessionStorage.getItem(SESSION_KEY)) return;   // already seen this session
        setTimeout(() => {
            const el = document.getElementById('adPopup');
            if (el) {
                el.style.display = 'flex';
                sessionStorage.setItem(SESSION_KEY, '1');
                if (TOTAL > 1) startAutoPlay();
            }
        }, 1200);
    }

    // ── Close ─────────────────────────────────────────────────────────────────
    window.closeAdPopup = function () {
        const el = document.getElementById('adPopup');
        if (el) el.style.display = 'none';
        stopAutoPlay();
    };

    // ── Dismiss forever ───────────────────────────────────────────────────────
    window.dismissPopupForever = function () {
        localStorage.setItem(STORAGE_KEY, '1');
        closeAdPopup();
    };

    // ── Slide navigation ──────────────────────────────────────────────────────
    window.popupSlide = function (dir) {
        goTo((currentSlide + dir + TOTAL) % TOTAL);
        resetAutoPlay();
    };

    window.popupGoTo = function (idx) {
        goTo(idx);
        resetAutoPlay();
    };

    function goTo(idx) {
        currentSlide = idx;
        const track = document.getElementById('popupTrack');
        if (track) track.style.transform = `translateX(-${idx * 100}%)`;
        updateDots(idx);
    }

    function updateDots(active) {
        for (let i = 0; i < TOTAL; i++) {
            const dot = document.getElementById('popupDot' + i);
            if (!dot) continue;
            dot.style.width      = i === active ? '22px' : '8px';
            dot.style.background = i === active ? '#fff' : 'rgba(255,255,255,0.45)';
        }
    }

    // ── Auto-play ─────────────────────────────────────────────────────────────
    function startAutoPlay() {
        autoTimer = setInterval(() => popupSlide(1), 3500);
    }
    function stopAutoPlay() {
        clearInterval(autoTimer);
    }
    function resetAutoPlay() {
        stopAutoPlay();
        if (TOTAL > 1) startAutoPlay();
    }

    // ── Close on overlay click ────────────────────────────────────────────────
    document.getElementById('adPopup').addEventListener('click', function (e) {
        if (e.target === this) closeAdPopup();
    });

    // ── Keyboard: Escape / arrow keys ────────────────────────────────────────
    document.addEventListener('keydown', function (e) {
        const popup = document.getElementById('adPopup');
        if (!popup || popup.style.display === 'none') return;
        if (e.key === 'Escape')      closeAdPopup();
        if (e.key === 'ArrowLeft')   popupSlide(-1);
        if (e.key === 'ArrowRight')  popupSlide(1);
    });

    // ── Boot ──────────────────────────────────────────────────────────────────
    maybeShow();
})();
</script>