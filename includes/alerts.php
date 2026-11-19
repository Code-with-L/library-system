<?php
/**
 * One-time flash messages.
 */
$flashes = get_flashes();

if ($flashes !== []):
?>
<div class="alerts" aria-live="polite">
    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert--<?= e($flash['type']) ?>" role="status">
            <p><?= e($flash['message']) ?></p>
            <button class="alert__close" type="button" data-dismiss-alert aria-label="Dismiss message">&times;</button>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
