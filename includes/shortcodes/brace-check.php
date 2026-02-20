<?php
// brace-check.php
// Finds the first place PHP brace depth goes negative or ends non-zero.

$target = __DIR__ . '/client-portal.php';
if (!file_exists($target)) {
    die("Target not found: " . htmlspecialchars($target));
}

$code = file_get_contents($target);
$tokens = token_get_all($code);

$depth = 0;
$line  = 1;

function token_line($tok) {
    if (is_array($tok)) return $tok[2];
    return null;
}

foreach ($tokens as $tok) {
    if (is_array($tok)) {
        $line = $tok[2];
        continue;
    }

    // Only count real braces in PHP tokens.
    // token_get_all returns '{' and '}' as single-character tokens.
    if ($tok === '{') {
        $depth++;
    } elseif ($tok === '}') {
        $depth--;
        if ($depth < 0) {
            echo "FIRST EXTRA '}' detected at approx line: {$line}\n";
            exit;
        }
    }
}

if ($depth !== 0) {
    echo "Brace depth ended at {$depth}. ";
    echo ($depth > 0) ? "Missing {$depth} closing '}'\n" : "Extra " . abs($depth) . " closing '}'\n";
} else {
    echo "Braces look balanced. If you still get parse errors, it may be parentheses, endif/endforeach mismatch, or stray PHP tag.\n";
}
