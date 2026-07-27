<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Services/QueryCreationDraftService.php';

$execute = in_array('--execute', $argv ?? [], true)
    || in_array('--force', $argv ?? [], true);
$dryRun = in_array('--dry-run', $argv ?? [], true) || !$execute;
$bufferSeconds = 3600;
$failed = 0;
$draftRows = 0;
$fileSets = 0;
$draftDirectory = QueryCreationDraftService::storageDirectoryPath();

/**
 * Delete the exact token-derived file set, or report it in dry-run mode.
 */
$cleanToken = static function (string $token, string $reason) use (
    $dryRun,
    $draftDirectory,
    &$failed,
    &$fileSets
): bool {
    try {
        $token = QueryCreationDraftService::validateToken($token);
        $names = [
            'draft_' . $token . '.dat',
            'temp_' . $token . '.dat',
            'source_' . $token . '.xlsx',
            'source_' . $token . '.xls',
        ];
        $existingCount = count(array_filter(
            $names,
            static fn(string $name): bool => is_file(
                $draftDirectory . DIRECTORY_SEPARATOR . $name
            ) && !is_link($draftDirectory . DIRECTORY_SEPARATOR . $name)
        ));
        if ($dryRun) {
            if ($existingCount > 0) {
                echo sprintf(
                    "[DRY-RUN] Would clean one %s file set (%d file(s)).\n",
                    $reason,
                    $existingCount
                );
                $fileSets++;
            }
        } else {
            $result = QueryCreationDraftService::deleteDraftFiles($token);
            if ($result['deleted'] > 0) {
                echo sprintf(
                    "[OK] Cleaned one %s file set (%d file(s)).\n",
                    $reason,
                    $result['deleted']
                );
                $fileSets++;
            }
        }
        return true;
    } catch (Throwable $exception) {
        $failed++;
        error_log(
            'Query creation draft prune failed: '
            . get_class($exception)
            . ' (' . $reason . ')'
        );
        echo sprintf("[ERROR] Failed to clean one %s file set.\n", $reason);
        return false;
    }
};

try {
    $pdo = db();
    $directory = $draftDirectory;

    $rows = $pdo->query(
        'SELECT id, token, expires_at, published_at
         FROM query_creation_drafts'
    )->fetchAll(PDO::FETCH_ASSOC);

    $knownTokens = [];
    foreach ($rows as $row) {
        $token = QueryCreationDraftService::validateToken((string) $row['token']);
        $knownTokens[$token] = true;

        $expired = empty($row['published_at'])
            && strtotime((string) $row['expires_at']) < time();
        $published = !empty($row['published_at']);
        if (!$expired && !$published) {
            continue;
        }

        $reason = $published ? 'published draft' : 'expired draft';
        $cleaned = $cleanToken($token, $reason);
        if (!$expired || !$cleaned) {
            continue;
        }

        if ($dryRun) {
            echo "[DRY-RUN] Would remove one expired draft row.\n";
            $draftRows++;
            continue;
        }

        $delete = $pdo->prepare(
            'DELETE FROM query_creation_drafts
             WHERE id = ? AND token = ? AND published_at IS NULL
               AND expires_at < NOW()'
        );
        $delete->execute([(int) $row['id'], $token]);
        if ($delete->rowCount() === 1) {
            $draftRows++;
        } else {
            $failed++;
            echo "[ERROR] An expired draft changed during pruning.\n";
        }
    }

    $orphanTokens = [];
    $iterator = new DirectoryIterator($directory);
    foreach ($iterator as $entry) {
        if (!$entry->isFile() || $entry->isLink()) {
            continue;
        }
        if ((time() - $entry->getMTime()) < $bufferSeconds) {
            continue;
        }
        if (
            preg_match(
                '/^(?:draft|temp|source)_([a-f0-9]{64})\.(?:dat|xlsx|xls)$/D',
                $entry->getFilename(),
                $matches
            ) !== 1
        ) {
            continue;
        }
        $token = $matches[1];
        if (!isset($knownTokens[$token])) {
            $orphanTokens[$token] = true;
        }
    }

    foreach (array_keys($orphanTokens) as $token) {
        $cleanToken($token, 'orphaned draft');
    }

    echo sprintf(
        "%s: draft rows=%d, file sets=%d, failures=%d\n",
        $dryRun ? 'Dry run complete' : 'Prune complete',
        $draftRows,
        $fileSets,
        $failed
    );
    exit($failed === 0 ? 0 : 2);
} catch (Throwable $exception) {
    error_log('Query draft pruning aborted: ' . get_class($exception));
    fwrite(STDERR, "Draft pruning failed. See the server error log.\n");
    exit(1);
}
