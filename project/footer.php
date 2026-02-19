<?php
// footer.php - Reusable footer for IT Shop.LK
?>

    <!-- ══════════════ WHATSAPP FAB ══════════════ -->
    <a href="https://wa.me/your-whatsapp-number"
       class="wa-fab" target="_blank" rel="noopener noreferrer"
       aria-label="Contact us on WhatsApp">
        <i class="fab fa-whatsapp"></i>
    </a>

    <!-- ══════════════ FOOTER ══════════════ -->
    <footer class="site-footer">
        <div class="sf-inner">

            <!-- TOP ROW -->
            <div class="sf-grid">

                <!-- Brand -->
                <div class="sf-brand">
                    <div class="sf-logo">IT Shop<em>.LK</em></div>
                    <p class="sf-tagline">Your trusted partner for electronics &amp; computer equipment in Sri Lanka.</p>
                    <div class="sf-socials">
                        <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                        <a href="#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                    </div>
                </div>

                <!-- Quick Links -->
                <div class="sf-col">
                    <h6 class="sf-heading">Quick Links</h6>
                    <ul>
                        <li><a href="index.php">Home</a></li>
                        <li><a href="products.php">Products</a></li>
                        <li><a href="rapidventure.php">Rapidventure</a></li>
                        <li><a href="contact.php">Contact</a></li>
                    </ul>
                </div>

                <!-- Categories -->
                <div class="sf-col">
                    <h6 class="sf-heading">Categories</h6>
                    <ul>
                        <li><a href="products.php?category=laptops">Laptops</a></li>
                        <li><a href="products.php?category=desktops">Desktop PCs</a></li>
                        <li><a href="products.php?category=graphics">Graphics Cards</a></li>
                        <li><a href="products.php?category=memory">RAM &amp; Storage</a></li>
                        <li><a href="products.php?category=peripherals">Peripherals</a></li>
                        <li><a href="products.php?category=audio">Audio Devices</a></li>
                    </ul>
                </div>

                <!-- Contact -->
                <div class="sf-col">
                    <h6 class="sf-heading">Get In Touch</h6>
                    <ul class="sf-contact">
                        <li>
                            <span class="sf-icon"><i class="fas fa-phone"></i></span>
                            <a href="tel:+94779005652">+94 77 900 5652</a>
                        </li>
                        <li>
                            <span class="sf-icon"><i class="fas fa-envelope"></i></span>
                            <a href="mailto:info@itshop.lk">info@itshop.lk</a>
                        </li>
                        <li>
                            <span class="sf-icon"><i class="fas fa-envelope"></i></span>
                            <a href="mailto:admin@itshop.lk">admin@itshop.lk</a>
                        </li>
                    </ul>
                </div>

            </div><!-- /sf-grid -->

            <!-- DIVIDER -->
            <div class="sf-rule"></div>

            <!-- BOTTOM BAR -->
            <div class="sf-bottom">
                <span class="sf-copy">&copy; <?php echo date('Y'); ?> IT Shop.LK. All rights reserved.</span>
                <div class="sf-legal">
                    <a href="privacy.php">Privacy Policy</a>
                    <a href="terms.php">Terms of Service</a>
                </div>
            </div>

        </div>
    </footer>

    <!-- ══════════════ FOOTER STYLES ══════════════ -->
    <style>
        /* ── WhatsApp FAB ── */
        .wa-fab {
            position: fixed;
            bottom: 24px; right: 24px;
            width: 54px; height: 54px;
            background: #25d366;
            color: #fff;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.45rem;
            z-index: 9999;
            text-decoration: none;
            box-shadow: 0 6px 20px rgba(37,211,102,.42);
            transition: transform .25s, box-shadow .25s;
        }
        .wa-fab:hover {
            transform: scale(1.1);
            box-shadow: 0 10px 28px rgba(37,211,102,.55);
            color: #fff;
            text-decoration: none;
        }

        /* ── Footer shell ── */
        .site-footer {
            background: #08080f;
            color: #c4c4de;
            font-family: 'Red Hat Display', sans-serif;
            -webkit-font-smoothing: antialiased;
            padding: 4.5rem 0 0;
        }
        .sf-inner {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        /* ── Grid ── */
        .sf-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1.2fr 1.4fr;
            gap: 3rem 2.5rem;
        }

        /* ── Brand col ── */
        .sf-logo {
            font-size: 1.65rem;
            font-weight: 900;
            color: #fff;
            letter-spacing: -.025em;
            margin-bottom: .8rem;
        }
        .sf-logo em {
            font-style: normal;
            background: linear-gradient(135deg, #00d10b 10%, #0023be 90%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .sf-tagline {
            font-size: .875rem;
            color: #8f8f9f;
            line-height: 1.7;
            max-width: 250px;
            margin-bottom: 1.5rem;
        }
        .sf-socials { display: flex; gap: 9px; }
        .sf-socials a {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.09);
            border: 1px solid rgba(255,255,255,.07);
            color: #6666888;
            color: #7777992;
            color: rgba(255, 255, 255, 0.6);
            display: flex; align-items: center; justify-content: center;
            font-size: .82rem;
            text-decoration: none;
            transition: background .2s, border-color .2s, color .2s;
        }
        .sf-socials a:hover {
            background: rgba(70, 229, 73, 0.2);
            border-color: rgba(97, 229, 70, 0.4);
            color: #ffffff;
        }

        /* ── Cols ── */
        .sf-heading {
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: #fff;
            margin-bottom: 1.1rem;
        }
        .sf-col ul { list-style: none; padding: 0; margin: 0; }
        .sf-col ul li { margin-bottom: .55rem; }
        .sf-col ul li a {
            font-size: .875rem;
            font-weight: 500;
            color: #a2a2a2;
            text-decoration: none;
            transition: color .15s;
        }
        .sf-col ul li a:hover { color: #0cb100; }

        /* ── Contact list ── */
        .sf-contact { list-style: none; padding: 0; margin: 0; }
        .sf-contact li {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: .8rem;
        }
        .sf-icon {
            width: 28px; height: 28px;
            border-radius: 8px;
            background: rgba(0, 212, 32, 0.22);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .sf-icon i { font-size: .68rem; color: #daffd7; }
        .sf-contact a {
            font-size: .875rem;
            font-weight: 500;
            color: #576e55;
            text-decoration: none;
            line-height: 1.4;
            transition: color .15s;
        }
        .sf-contact a:hover { color: #bbfca5; }

        /* ── Rule ── */
        .sf-rule {
            height: 1px;
            background: rgba(255,255,255,.055);
            margin: 3rem 0 0;
        }

        /* ── Bottom bar ── */
        .sf-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.2rem 0;
            flex-wrap: wrap;
            gap: .75rem;
        }
        .sf-copy {
            font-size: .8rem;
            font-weight: 500;
            color: #969696;
        }
        .sf-legal { display: flex; gap: 1.5rem; }
        .sf-legal a {
            font-size: .8rem;
            font-weight: 500;
            color: #a5a5ae;
            text-decoration: none;
            transition: color .15s;
        }
        .sf-legal a:hover { color: #0cb100; }

        /* ── Responsive ── */
        @media (max-width: 900px) {
            .sf-grid { grid-template-columns: 1fr 1fr; }
            .sf-brand { grid-column: 1 / -1; }
        }
        @media (max-width: 540px) {
            .sf-grid { grid-template-columns: 1fr; }
            .sf-bottom { flex-direction: column; align-items: flex-start; }
        }
    </style>

    <!-- Bootstrap JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>

    <script>
        document.querySelectorAll('a[href^="#"]').forEach(a => {
            a.addEventListener('click', e => {
                const t = document.querySelector(a.getAttribute('href'));
                if (t) { e.preventDefault(); t.scrollIntoView({ behavior: 'smooth' }); }
            });
        });
    </script>

    <?php if (!empty($extra_scripts)) echo $extra_scripts; ?>

</body>
</html>