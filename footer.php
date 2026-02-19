<?php
// footer.php - Reusable footer for IT Shop.LK
?>

    <!-- WhatsApp Floating Button -->
    <a href="https://wa.me/your-whatsapp-number" class="whatsapp-btn" target="_blank" rel="noopener noreferrer" aria-label="Contact us on WhatsApp">
        <i class="fab fa-whatsapp"></i>
    </a>

    <!-- Footer -->
    <footer class="bg-dark text-light py-5">
        <div class="container">
            <div class="row">
                <!-- Brand -->
                <div class="col-lg-4 mb-4">
                    <h5 class="fw-bold mb-3">IT Shop.LK</h5>
                    <p class="text-secondary">Your trusted partner for electronics and computer equipment in Sri Lanka.</p>
                    <div class="social-links mt-3">
                        <a href="#" class="text-light me-3" aria-label="Facebook"><i class="fab fa-facebook fa-lg"></i></a>
                        <a href="#" class="text-light me-3" aria-label="Instagram"><i class="fab fa-instagram fa-lg"></i></a>
                        <a href="#" class="text-light" aria-label="Twitter"><i class="fab fa-twitter fa-lg"></i></a>
                    </div>
                </div>

                <!-- Quick Links -->
                <div class="col-lg-2 col-md-4 mb-4">
                    <h6 class="fw-bold mb-3">Quick Links</h6>
                    <ul class="list-unstyled">
                        <li class="mb-1"><a href="index.php" class="text-secondary text-decoration-none">Home</a></li>
                        <li class="mb-1"><a href="products.php" class="text-secondary text-decoration-none">Products</a></li>
                        <li class="mb-1"><a href="rapidventure.php" class="text-secondary text-decoration-none">Rapidventure</a></li>
                        <li class="mb-1"><a href="contact.php" class="text-secondary text-decoration-none">Contact</a></li>
                    </ul>
                </div>

                <!-- Categories -->
                <div class="col-lg-3 col-md-4 mb-4">
                    <h6 class="fw-bold mb-3">Categories</h6>
                    <ul class="list-unstyled">
                        <li class="mb-1"><a href="products.php?category=laptops" class="text-secondary text-decoration-none">Laptops</a></li>
                        <li class="mb-1"><a href="products.php?category=desktops" class="text-secondary text-decoration-none">Desktop PCs</a></li>
                        <li class="mb-1"><a href="products.php?category=graphics" class="text-secondary text-decoration-none">Graphics Cards</a></li>
                        <li class="mb-1"><a href="products.php?category=memory" class="text-secondary text-decoration-none">RAM &amp; Storage</a></li>
                        <li class="mb-1"><a href="products.php?category=peripherals" class="text-secondary text-decoration-none">Peripherals</a></li>
                        <li class="mb-1"><a href="products.php?category=audio" class="text-secondary text-decoration-none">Audio Devices</a></li>
                    </ul>
                </div>

                <!-- Contact Info -->
                <div class="col-lg-3 col-md-4 mb-4">
                    <h6 class="fw-bold mb-3">Contact Info</h6>
                    <ul class="list-unstyled text-secondary">
                        <li class="mb-2"><i class="fas fa-envelope me-2"></i><a href="mailto:admin@itshop.lk" class="text-secondary text-decoration-none">admin@itshop.lk</a></li>
                        <li class="mb-2"><i class="fas fa-phone me-2"></i><a href="tel:+94779005652" class="text-secondary text-decoration-none">+94 77 900 5652</a></li>
                        <li class="mb-2"><i class="fas fa-envelope me-2"></i><a href="mailto:info@itshop.lk" class="text-secondary text-decoration-none">info@itshop.lk</a></li>
                    </ul>
                </div>
            </div>

            <hr class="border-secondary my-4">

            <div class="row align-items-center">
                <div class="col-md-6 text-secondary">
                    <small>&copy; <?php echo date('Y'); ?> IT Shop.LK. All rights reserved.</small>
                </div>
                <div class="col-md-6 text-md-end mt-2 mt-md-0">
                    <a href="privacy.php" class="text-secondary text-decoration-none me-3"><small>Privacy Policy</small></a>
                    <a href="terms.php" class="text-secondary text-decoration-none"><small>Terms of Service</small></a>
                </div>
            </div>
        </div>
    </footer>
    <!-- /Footer -->

    <style>
        footer a {
            transition: color 0.25s ease;
        }
        footer a:hover {
            color: var(--primary-color, #ffffff) !important;
        }
        footer .social-links a:hover {
            color: var(--primary-color, #ffffff) !important;
            transform: translateY(-3px);
            display: inline-block;
            transition: color 0.25s ease, transform 0.25s ease;
        }
    </style>

    <!-- Bootstrap JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>

    <!-- Global Scripts -->
    <script>
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });

        // Navbar scroll effect
        window.addEventListener('scroll', function () {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 100) {
                navbar.style.background = 'rgba(255, 255, 255, 0.95)';
                navbar.style.backdropFilter = 'blur(10px)';
            } else {
                navbar.style.background = 'white';
                navbar.style.backdropFilter = 'none';
            }
        });
    </script>

    <!-- Page-specific scripts can be added by pages before including footer, via $extra_scripts -->
    <?php if (!empty($extra_scripts)) echo $extra_scripts; ?>

</body>
</html>