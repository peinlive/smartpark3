<?php
// /home/myzonaco/smartpark.myzona360.com/includes/footer.php
if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
    </main>
</div>

<footer class="footer">
    <small>SmartPark v<?= e(APP_VERSION) ?> · <?= date('Y') ?></small>
</footer>

<script src="<?= asset('js/app.js') ?>?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>
