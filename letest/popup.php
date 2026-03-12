<?php
/*
 * AD POPUP — include 'popup.php'; in index.php after header.php
 * Images managed via Admin Panel → Media → Popup Images
 */

$popup_img_prefix = '';

$adImages = [];
if (!empty($pdo)) {
    try {
        $stmt = $pdo->query(
            "SELECT image_src, alt_text, link_url
             FROM popup_images
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC"
        );
        $adImages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

if (empty($adImages)) return;
?>

<div id="itshop-ad-overlay"
     style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;z-index:2147483647;background:rgba(5,5,12,0.88);align-items:center;justify-content:center;padding:1rem;">

    <div style="position:relative;width:100%;max-width:480px;border-radius:20px;overflow:hidden;background:#fff;box-shadow:0 40px 100px rgba(0,0,0,0.7);">

        <button onclick="itshopAdClose()"
                style="position:absolute;top:12px;right:12px;z-index:10;width:38px;height:38px;border-radius:50%;background:rgba(0,0,0,0.65);border:2px solid rgba(255,255,255,0.4);color:#fff;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;">&#x2715;</button>

        <div style="position:relative;width:100%;overflow:hidden;">
            <div id="itshop-ad-track" style="display:flex;transition:transform 0.45s ease;">
                <?php foreach ($adImages as $img):
                    $src = $popup_img_prefix . ltrim($img['image_src'], '/');
                    $has_link = !empty($img['link_url']);
                ?>
                    <?php if ($has_link): ?>
                    <a href="<?= htmlspecialchars($img['link_url']) ?>" style="display:block;flex-shrink:0;width:100%;">
                        <img src="<?= htmlspecialchars($src) ?>" alt="<?= htmlspecialchars($img['alt_text'] ?? '') ?>" style="display:block;width:100%;height:auto;">
                    </a>
                    <?php else: ?>
                    <img src="<?= htmlspecialchars($src) ?>" alt="<?= htmlspecialchars($img['alt_text'] ?? '') ?>" style="display:block;width:100%;height:auto;flex-shrink:0;">
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <?php if (count($adImages) > 1): ?>
            <div style="position:absolute;bottom:10px;left:50%;transform:translateX(-50%);display:flex;gap:6px;z-index:8;">
                <?php foreach ($adImages as $i => $img): ?>
                <button onclick="itshopAdGoTo(<?= $i ?>)"
                        id="itshop-dot-<?= $i ?>"
                        style="width:8px;height:8px;border-radius:50%;background:<?= $i===0?'#fff':'rgba(255,255,255,0.4)' ?>;border:none;cursor:pointer;padding:0;"></button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
(function(){
    var _adEl    = document.getElementById('itshop-ad-overlay');
    var _adTrack = document.getElementById('itshop-ad-track');
    var _adTotal = <?= count($adImages) ?>;
    var _adCur   = 0;
    var _adTimer = null;

    window.itshopAdClose = function() {
        clearInterval(_adTimer);
        _adEl.style.display = 'none';
        document.body.style.overflow = '';
    };

    window.itshopAdGoTo = function(n) {
        _adCur = ((n % _adTotal) + _adTotal) % _adTotal;
        _adTrack.style.transform = 'translateX(-' + (_adCur * 100) + '%)';
        for (var i = 0; i < _adTotal; i++) {
            var dot = document.getElementById('itshop-dot-' + i);
            if (dot) dot.style.background = i === _adCur ? '#fff' : 'rgba(255,255,255,0.4)';
        }
    };

    function _adShow() {
        _adEl.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        if (_adTotal > 1) {
            _adTimer = setInterval(function(){ itshopAdGoTo(_adCur + 1); }, 4000);
        }
    }

    // Close on backdrop click
    _adEl.onclick = function(e) {
        if (e.target === _adEl) itshopAdClose();
    };

    // ESC key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') itshopAdClose();
    });

    // Swipe
    var _adTx = 0;
    _adTrack.addEventListener('touchstart', function(e){ _adTx = e.touches[0].clientX; }, {passive:true});
    _adTrack.addEventListener('touchend', function(e){
        var dx = _adTx - e.changedTouches[0].clientX;
        if (Math.abs(dx) > 40) itshopAdGoTo(dx > 0 ? _adCur+1 : _adCur-1);
    }, {passive:true});

    // Show after 1 second
    setTimeout(_adShow, 1000);
})();
</script>