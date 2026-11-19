<?php
/**
 * Render a pagination control.
 *
 * @param int                  $page       Current page, starting at 1.
 * @param int                  $totalPages Total number of pages.
 * @param string               $basePath   Application path, e.g. admin/users.php.
 * @param array<string, mixed> $params     Query parameters to preserve.
 */
function render_pagination(int $page, int $totalPages, string $basePath, array $params = []): void
{
    if ($totalPages <= 1) {
        return;
    }

    $page = max(1, min($page, $totalPages));

    $linkFor = static function (int $target) use ($basePath, $params): string {
        $query = $params;
        $query['page'] = $target;
        return url($basePath . '?' . http_build_query($query));
    };

    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    ?>
    <nav class="pagination" aria-label="Pagination">
        <?php if ($page > 1): ?>
            <a class="pagination__link" href="<?= e($linkFor($page - 1)) ?>" rel="prev">Previous</a>
        <?php else: ?>
            <span class="pagination__link is-disabled">Previous</span>
        <?php endif; ?>

        <?php if ($start > 1): ?>
            <a class="pagination__link" href="<?= e($linkFor(1)) ?>">1</a>
            <?php if ($start > 2): ?><span class="pagination__ellipsis">&hellip;</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($index = $start; $index <= $end; $index++): ?>
            <?php if ($index === $page): ?>
                <span class="pagination__link is-current" aria-current="page"><?= e($index) ?></span>
            <?php else: ?>
                <a class="pagination__link" href="<?= e($linkFor($index)) ?>"><?= e($index) ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($end < $totalPages): ?>
            <?php if ($end < $totalPages - 1): ?><span class="pagination__ellipsis">&hellip;</span><?php endif; ?>
            <a class="pagination__link" href="<?= e($linkFor($totalPages)) ?>"><?= e($totalPages) ?></a>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
            <a class="pagination__link" href="<?= e($linkFor($page + 1)) ?>" rel="next">Next</a>
        <?php else: ?>
            <span class="pagination__link is-disabled">Next</span>
        <?php endif; ?>
    </nav>
    <?php
}
