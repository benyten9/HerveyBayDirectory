<?php
/**
 * BLAKE3 (hash mode, 32-byte output) in plain PHP.
 *
 * Cloudflare Pages names every uploaded file by a BLAKE3 hash, and PHP has
 * no BLAKE3 of its own (the native one is used when a build has it). Speed
 * is a few MB a second; publishing keeps each file's hash, so a file is
 * hashed once until it changes.
 *
 * @package NibWP
 */

if (!defined('ABSPATH')) {
    exit;
}

/** BLAKE3 of a string, as 64 hex characters. */
function nibwp_blake3(string $data): string
{
    if (in_array('blake3', hash_algos(), true)) {
        return hash('blake3', $data);
    }
    $iv = [0x6A09E667, 0xBB67AE85, 0x3C6EF372, 0xA54FF53A, 0x510E527F, 0x9B05688C, 0x1F83D9AB, 0x5BE0CD19];
    $len = strlen($data);
    $chunks = max(1, intdiv($len + 1023, 1024));
    $stack = [];
    $cv = $iv;
    for ($c = 0; $c < $chunks; $c++) {
        $chunk = substr($data, $c * 1024, 1024);
        $blocks = max(1, intdiv(strlen($chunk) + 63, 64));
        $cv = $iv;
        for ($b = 0; $b < $blocks; $b++) {
            $block = substr($chunk, $b * 64, 64);
            $flags = ($b === 0 ? 1 : 0) | ($b === $blocks - 1 ? 2 : 0) | ($chunks === 1 && $b === $blocks - 1 ? 8 : 0);
            $cv = nibwp_blake3_compress($cv, array_values(unpack('V16', str_pad($block, 64, "\0"))), $c, strlen($block), $flags);
        }
        if ($c === $chunks - 1) {
            break;
        }
        // A finished chunk joins the tree: merge equal-sized subtrees.
        $total = $c + 1;
        while (($total & 1) === 0) {
            $cv = nibwp_blake3_compress($iv, array_merge(array_pop($stack), $cv), 0, 64, 4);
            $total >>= 1;
        }
        $stack[] = $cv;
    }
    for ($i = count($stack) - 1; $i >= 0; $i--) {
        $cv = nibwp_blake3_compress($iv, array_merge($stack[$i], $cv), 0, 64, 4 | ($i === 0 ? 8 : 0));
    }

    return bin2hex(pack('V8', ...$cv));
}

/**
 * The compression function; returns the 8-word chaining value.
 *
 * @param int[] $cv 8 words.
 * @param int[] $m  16 message words.
 * @return int[]
 */
function nibwp_blake3_compress(array $cv, array $m, int $counter, int $len, int $flags): array
{
    static $sched = null;
    static $g = [[0, 4, 8, 12, 0, 1], [1, 5, 9, 13, 2, 3], [2, 6, 10, 14, 4, 5], [3, 7, 11, 15, 6, 7], [0, 5, 10, 15, 8, 9], [1, 6, 11, 12, 10, 11], [2, 7, 8, 13, 12, 13], [3, 4, 9, 14, 14, 15]];
    if ($sched === null) {
        $perm = [2, 6, 3, 10, 7, 0, 4, 13, 1, 11, 12, 5, 9, 14, 15, 8];
        $sched = [range(0, 15)];
        for ($r = 1; $r < 7; $r++) {
            foreach ($perm as $i => $p) {
                $sched[$r][$i] = $sched[$r - 1][$p];
            }
        }
    }
    $v = [$cv[0], $cv[1], $cv[2], $cv[3], $cv[4], $cv[5], $cv[6], $cv[7], 0x6A09E667, 0xBB67AE85, 0x3C6EF372, 0xA54FF53A, $counter & 0xffffffff, ($counter >> 32) & 0xffffffff, $len, $flags];
    foreach ($sched as $s) {
        foreach ($g as [$a, $b, $c, $d, $x, $y]) {
            $va = ($v[$a] + $v[$b] + $m[$s[$x]]) & 0xffffffff;
            $vd = $v[$d] ^ $va;
            $vd = (($vd >> 16) | ($vd << 16)) & 0xffffffff;
            $vc = ($v[$c] + $vd) & 0xffffffff;
            $vb = $v[$b] ^ $vc;
            $vb = (($vb >> 12) | ($vb << 20)) & 0xffffffff;
            $va = ($va + $vb + $m[$s[$y]]) & 0xffffffff;
            $vd ^= $va;
            $vd = (($vd >> 8) | ($vd << 24)) & 0xffffffff;
            $vc = ($vc + $vd) & 0xffffffff;
            $vb ^= $vc;
            $vb = (($vb >> 7) | ($vb << 25)) & 0xffffffff;
            $v[$a] = $va;
            $v[$b] = $vb;
            $v[$c] = $vc;
            $v[$d] = $vd;
        }
    }

    return [$v[0] ^ $v[8], $v[1] ^ $v[9], $v[2] ^ $v[10], $v[3] ^ $v[11], $v[4] ^ $v[12], $v[5] ^ $v[13], $v[6] ^ $v[14], $v[7] ^ $v[15]];
}

/** A file's name in Cloudflare Pages' upload store, as wrangler makes it. */
function nibwp_static_cf_hash(string $contents, string $path): string
{
    return substr(nibwp_blake3(base64_encode($contents) . (string) pathinfo($path, PATHINFO_EXTENSION)), 0, 32);
}
