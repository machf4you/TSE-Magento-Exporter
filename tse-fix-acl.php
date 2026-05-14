<?php
/**
 * TSE ACL Auto-Patcher
 *
 * Magento 2.4.7+ tightened the ACL XSD — every <resource> node now requires
 * a `title` attribute. Many older third-party modules omit it on parent
 * reference nodes (e.g. <resource id="Magento_Backend::admin"> with no title),
 * which makes the merged ACL XML fail validation and crashes ACL building.
 *
 * Usage (from your Magento root):
 *
 *   php tse-fix-acl.php                  # dry-run (default): show what would change
 *   php tse-fix-acl.php --write          # actually patch the files (creates .bak backups)
 *   php tse-fix-acl.php --write --vendor # also patch vendor/ acl.xml files
 *
 * Safe by default (dry-run). Every patched file is backed up to
 * <file>.bak.<timestamp> before it's overwritten.
 */

$write       = in_array('--write',  $argv ?? [], true);
$alsoVendor  = in_array('--vendor', $argv ?? [], true);

$roots = ['app/code'];
if ($alsoVendor) $roots[] = 'vendor';

$found = [];
foreach ($roots as $root) {
    if (! is_dir($root)) continue;
    $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $f) {
        if (! $f->isFile()) continue;
        if ($f->getBasename() !== 'acl.xml') continue;
        // Only Magento ACL config files — they must have <config><acl>.
        $path = $f->getPathname();
        $head = @file_get_contents($path, false, null, 0, 2048);
        if (false === $head) continue;
        if (false === stripos($head, '<acl')) continue;
        $found[] = $path;
    }
}

if (! $found) {
    echo "No acl.xml files found under: " . implode(', ', $roots) . "\n";
    exit(0);
}

echo ($write ? "PATCH MODE" : "DRY-RUN MODE") . " — scanning " . count($found) . " acl.xml file(s)\n\n";

$totalFixed = 0;
$filesFixed = 0;

foreach ($found as $path) {
    $xml = file_get_contents($path);
    if (false === $xml) { echo "[SKIP] cannot read $path\n"; continue; }

    $dom = new \DOMDocument();
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput       = false;
    $prev = libxml_use_internal_errors(true);
    $ok = $dom->loadXML($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (! $ok) { echo "[SKIP] not parseable: $path\n"; continue; }

    $resources = $dom->getElementsByTagName('resource');
    $missing = [];
    foreach ($resources as $r) {
        if (! $r->hasAttribute('title')) $missing[] = $r;
    }
    if (! $missing) {
        echo "[OK]  " . $path . "  (already valid)\n";
        continue;
    }

    foreach ($missing as $r) {
        $id    = $r->getAttribute('id');
        $title = deriveTitle($id);
        $r->setAttribute('title', $title);
        $totalFixed++;
    }
    $filesFixed++;
    echo "[FIX] " . $path . "  (added " . count($missing) . " missing title attribute(s))\n";

    if ($write) {
        $backup = $path . '.bak.' . date('YmdHis');
        if (! copy($path, $backup)) {
            echo "      ! failed to write backup $backup — aborting this file\n";
            continue;
        }
        $newXml = $dom->saveXML();
        if (false === $newXml || false === file_put_contents($path, $newXml)) {
            echo "      ! failed to write $path — restoring from backup\n";
            copy($backup, $path);
        } else {
            echo "      backup saved to: $backup\n";
        }
    }
}

echo "\n";
echo "Files needing fixes: $filesFixed\n";
echo "Resource nodes patched: $totalFixed\n";

if (! $write) {
    echo "\nThis was a DRY-RUN. To actually apply: php tse-fix-acl.php --write\n";
} else {
    echo "\nDone. Next steps:\n";
    echo "  bin/magento cache:flush\n";
    echo "  log out of admin → log back in\n";
}

/**
 * Build a reasonable `title` for a resource id like 'Magento_Backend::system'.
 * Falls back to "Resource" if id is blank.
 */
function deriveTitle(string $id): string {
    if ('' === $id) return 'Resource';
    $tail = $id;
    if (false !== ($pos = strrpos($id, '::'))) $tail = substr($id, $pos + 2);
    $tail = str_replace(['_', '-'], ' ', $tail);
    return ucwords(trim($tail));
}
