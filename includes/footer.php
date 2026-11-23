<?php
/**
 * Closes the layout opened by header.php.
 * Expects $libraryName from header.php.
 */
$currentYear = date('Y');
?>
        </main>

        <footer class="site-footer">
            <p>&copy; <?= e($currentYear) ?> <?= e($libraryName ?? 'Campus Library') ?>. All rights reserved.</p>
            <p class="site-footer__meta">Catalogue, borrowing and library records for students and staff.</p>
        </footer>
    </div>
</div>
<script src="<?= e(url('assets/js/app.js?v=8')) ?>" defer></script>
</body>
</html>
