<?php
/**
 * ابزار تک‌فایلی نگهداری وردپرس
 * نسخهٔ ساده‌شده برای استفاده آموزشی.
 * پیش‌نیاز: PHP 7.4+, PDO, GD یا Imagick
 * هشدار: قبل از استفاده حتماً از سایت خود بک‌آپ تهیه کنید.
 */

session_start();
header('Content-Type: text/html; charset=utf-8');

// ---- تنظیمات اولیه ----
$auth_pass = 'changeme'; // برای امنیت، بعد از آپلود این رمز را تغییر دهید
$upload_dir = __DIR__ . '/wp-content/uploads';
$backup_dir = $upload_dir . '/wp-maintenance-backups';
if (!is_dir($backup_dir)) @mkdir($backup_dir, 0755, true);

// ورود ساده
if (!isset($_SESSION['logged'])) {
    if (isset($_POST['auth']) && $_POST['auth'] === $auth_pass) {
        $_SESSION['logged'] = true;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>ورود</title></head><body class="p-8">';
    echo '<form method="post" class="space-y-4"><input type="password" name="auth" placeholder="رمز" class="border p-2"><button class="bg-blue-500 text-white px-4 py-2">ورود</button></form>';
    echo '</body></html>';
    exit;
}

// توکن CSRF
if (empty($_SESSION['token'])) {
    $_SESSION['token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['token'];

// بررسی پیش‌نیازها
$errors = [];
if (version_compare(PHP_VERSION, '7.4', '<')) $errors[] = 'PHP باید 7.4 یا بالاتر باشد.';
foreach (['pdo','pdo_mysql'] as $ext) if (!extension_loaded($ext)) $errors[] = "افزونۀ $ext فعال نیست.";
if (!extension_loaded('gd') && !extension_loaded('imagick')) $errors[] = 'GD یا Imagick لازم است.';

// تابع کمکی نمایش خطا
function show_errors($errors){
    foreach ($errors as $e) echo "<div class='bg-red-100 text-red-800 p-2 mb-2'>".htmlspecialchars($e)."</div>";
}

// تلاش برای خواندن wp-config.php
$wp_config = __DIR__ . '/wp-config.php';
$db = ['host'=>'','name'=>'','user'=>'','pass'=>'','prefix'=>'wp_'];
if (isset($_POST['load_config'])) {
    if (file_exists($wp_config)) {
        $config = file_get_contents($wp_config);
        foreach (['DB_NAME','DB_USER','DB_PASSWORD','DB_HOST','table_prefix'] as $item) {
            if (preg_match("/define\(\s*'{$item}'\s*,\s*'([^']+)'\s*\)/", $config, $m)) {
                switch ($item) {
                    case 'DB_NAME': $db['name']=$m[1]; break;
                    case 'DB_USER': $db['user']=$m[1]; break;
                    case 'DB_PASSWORD': $db['pass']=$m[1]; break;
                    case 'DB_HOST': $db['host']=$m[1]; break;
                }
            }
        }
        if (preg_match("/\$table_prefix\s*=\s*'([^']+)'/", $config, $m)) $db['prefix']=$m[1];
    }
}

// اتصال به دیتابیس
$pdo = null; $db_error = '';
if (isset($_POST['connect']) && hash_equals($csrf, $_POST['csrf'])) {
    $db['host'] = $_POST['db_host'];
    $db['name'] = $_POST['db_name'];
    $db['user'] = $_POST['db_user'];
    $db['pass'] = $_POST['db_pass'];
    $db['prefix'] = $_POST['db_prefix'];
    try {
        $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    } catch (Exception $e) {
        $db_error = $e->getMessage();
    }
}

// عملیات پاک‌سازی
$action_msg='';
if ($pdo && isset($_POST['action']) && hash_equals($csrf, $_POST['csrf'])) {
    $action = $_POST['action'];
    // بک‌آپ بگیر
    $backup_file = $backup_dir . '/db-' . date('Ymd_His') . '.sql';
    $cmd = "mysqldump --user={$db['user']} --password='{$db['pass']}' --host={$db['host']} {$db['name']} > $backup_file";
    $ok = false;
    if (function_exists('exec')) {
        @exec($cmd, $o, $r);
        $ok = ($r===0);
    }
    if (!$ok) {
        // fallback
        $f = fopen($backup_file,'w');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            fwrite($f, $create['Create Table'] . ";\n\n");
            $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $vals = array_map(function($v){return isset($v)?"'".addslashes($v)."'":"NULL";}, $row);
                fwrite($f, "INSERT INTO `$table` VALUES (".implode(',', $vals).");\n");
            }
            fwrite($f, "\n");
        }
        fclose($f);
    }

    switch($action){
        case 'clear_revisions':
            $stmt=$pdo->prepare("DELETE FROM `{$db['prefix']}posts` WHERE post_type='revision'");
            $stmt->execute();
            $action_msg='رِویژن‌ها حذف شدند';
            break;
        case 'clear_transients':
            $stmt=$pdo->prepare("DELETE FROM `{$db['prefix']}options` WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");
            $stmt->execute();
            $action_msg='ترنزینت‌ها حذف شدند';
            break;
        case 'clear_spam':
            $stmt=$pdo->prepare("DELETE FROM `{$db['prefix']}comments` WHERE comment_approved IN ('spam','trash')");
            $stmt->execute();
            $action_msg='کامنت‌های اسپم حذف شدند';
            break;
        case 'clear_orphan_meta':
            $stmt=$pdo->prepare("DELETE pm FROM `{$db['prefix']}postmeta` pm LEFT JOIN `{$db['prefix']}posts` p ON pm.post_id=p.ID WHERE p.ID IS NULL");
            $stmt->execute();
            $action_msg='متادیتای یتیم حذف شد';
            break;
    }
}

// تحلیل دیتابیس
$db_tables=[]; $revisions=0; $orphan_meta=0; $autoload_options=[]; $spam_comments=0;
if ($pdo) {
    $db_tables = $pdo->query("SELECT TABLE_NAME as name, TABLE_ROWS as rows, ROUND((DATA_LENGTH+INDEX_LENGTH)/1024/1024,2) as size FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$db['name']}' ORDER BY size DESC")->fetchAll(PDO::FETCH_ASSOC);
    $revisions = $pdo->query("SELECT COUNT(*) FROM `{$db['prefix']}posts` WHERE post_type='revision'")->fetchColumn();
    $orphan_meta = $pdo->query("SELECT COUNT(*) FROM `{$db['prefix']}postmeta` pm LEFT JOIN `{$db['prefix']}posts` p ON pm.post_id=p.ID WHERE p.ID IS NULL")->fetchColumn();
    $autoload_options = $pdo->query("SELECT option_name, LENGTH(option_value) AS len FROM `{$db['prefix']}options` WHERE autoload='yes' ORDER BY len DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    $spam_comments = $pdo->query("SELECT COUNT(*) FROM `{$db['prefix']}comments` WHERE comment_approved IN ('spam','trash')")->fetchColumn();
}

// اسکن آپلودها
$upload_stats = ['total'=>0,'size'=>0,'ext'=>[],'top'=>[]];
if (is_dir($upload_dir)) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($upload_dir));
    foreach ($rii as $file) {
        if ($file->isDir()) continue;
        $upload_stats['total']++;
        $upload_stats['size'] += $file->getSize();
        $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
        $upload_stats['ext'][$ext] = ($upload_stats['ext'][$ext] ?? 0) + $file->getSize();
        $upload_stats['top'][] = ['path'=>$file->getPathname(),'size'=>$file->getSize()];
    }
    usort($upload_stats['top'], function($a,$b){return $b['size'] <=> $a['size'];});
    $upload_stats['top'] = array_slice($upload_stats['top'],0,20);
}

?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<title>ابزار نگهداری وردپرس</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@3.4.4/dist/tailwind.min.css">
<link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazir-font@v30.1.0/dist/font-face.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" defer></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
<style>body{font-family:'Vazir',sans-serif}</style>
</head>
<body class="p-4 bg-gray-100" x-data="{tab:1}">
<h1 class="text-2xl mb-4">پنل نگهداری وردپرس</h1>
<?php if($errors) show_errors($errors); ?>

<div class="mb-4">
<button @click="tab=1" class="px-3 py-2" :class="{'bg-white':tab===1}">اتصال دیتابیس</button>
<button @click="tab=2" class="px-3 py-2" :class="{'bg-white':tab===2}">تحلیل دیتابیس</button>
<button @click="tab=3" class="px-3 py-2" :class="{'bg-white':tab===3}">فایل‌های آپلود</button>
<button @click="tab=4" class="px-3 py-2" :class="{'bg-white':tab===4}">لاگ‌ها/بک‌آپ</button>
</div>

<div x-show="tab===1" class="bg-white p-4 shadow">
<h2 class="text-xl mb-4">اتصال به دیتابیس</h2>
<form method="post" class="grid grid-cols-2 gap-4">
<input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
<label>هاست<input class="border p-1 w-full" name="db_host" value="<?php echo htmlspecialchars($db['host']); ?>"></label>
<label>نام دیتابیس<input class="border p-1 w-full" name="db_name" value="<?php echo htmlspecialchars($db['name']); ?>"></label>
<label>کاربر<input class="border p-1 w-full" name="db_user" value="<?php echo htmlspecialchars($db['user']); ?>"></label>
<label>رمز<input class="border p-1 w-full" type="password" name="db_pass" value="<?php echo htmlspecialchars($db['pass']); ?>"></label>
<label>پیشوند جداول<input class="border p-1 w-full" name="db_prefix" value="<?php echo htmlspecialchars($db['prefix']); ?>"></label>
<div class="col-span-2 flex space-x-2"><button name="connect" value="1" class="bg-blue-500 text-white px-4 py-2">اتصال</button><button name="load_config" value="1" class="bg-gray-300 px-4 py-2">خواندن از wp-config.php</button></div>
</form>
<?php if($pdo){echo '<div class="text-green-600 mt-4">اتصال موفق بود.</div>';}elseif($db_error){echo '<div class="text-red-600 mt-4">خطا: '.htmlspecialchars($db_error).'</div>';}
?>
</div>

<div x-show="tab===2" class="bg-white p-4 shadow">
<h2 class="text-xl mb-4">تحلیل دیتابیس</h2>
<?php if(!$pdo){echo 'ابتدا اتصال را برقرار کنید.';}else{ ?>
<?php if($action_msg) echo "<div class='bg-green-100 p-2 mb-2'>$action_msg</div>"; ?>
<table class="min-w-full text-sm mb-4"><thead><tr><th>نام جدول</th><th>ردیف‌ها</th><th>حجم (MB)</th></tr></thead><tbody>
<?php foreach($db_tables as $t){echo "<tr><td>{$t['name']}</td><td>{$t['rows']}</td><td>{$t['size']}</td></tr>";} ?>
</tbody></table>
<div class="grid grid-cols-2 gap-4">
<div>تعداد رِویژن‌ها: <?php echo $revisions; ?> <form method="post" class="inline"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><button name="action" value="clear_revisions" class="bg-red-500 text-white px-2 py-1">حذف</button></form></div>
<div>متادیتای یتیم: <?php echo $orphan_meta; ?> <form method="post" class="inline"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><button name="action" value="clear_orphan_meta" class="bg-red-500 text-white px-2 py-1">حذف</button></form></div>
<div>کامنت اسپم/زباله: <?php echo $spam_comments; ?> <form method="post" class="inline"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><button name="action" value="clear_spam" class="bg-red-500 text-white px-2 py-1">حذف</button></form></div>
<div><form method="post" class="inline"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><button name="action" value="clear_transients" class="bg-red-500 text-white px-2 py-1">حذف ترنزینت‌ها</button></form></div>
</div>
<h3 class="text-lg mt-6">بزرگترین گزینه‌های autoload</h3>
<table class="text-sm"><thead><tr><th>نام گزینه</th><th>حجم</th></tr></thead><tbody>
<?php foreach($autoload_options as $o){echo "<tr><td>{$o['option_name']}</td><td>{$o['len']}</td></tr>";} ?>
</tbody></table>
<?php } ?>
</div>

<div x-show="tab===3" class="bg-white p-4 shadow">
<h2 class="text-xl mb-4">آنالیز پوشه uploads</h2>
<p>تعداد فایل: <?php echo $upload_stats['total']; ?> - حجم کل: <?php echo round($upload_stats['size']/1024/1024,2); ?> MB</p>
<canvas id="chart" height="100"></canvas>
<script>
document.addEventListener('DOMContentLoaded',function(){
var ctx=document.getElementById('chart');
var data={labels:<?php echo json_encode(array_keys($upload_stats['ext'])); ?>,datasets:[{data:<?php echo json_encode(array_values($upload_stats['ext'])); ?>,backgroundColor:['#4e79a7','#f28e2b','#e15759','#76b7b2','#59a14f','#edc949','#af7aa1','#ff9da7','#9c755f','#bab0ab']}]};
new Chart(ctx,{type:'pie',data:data});
});
</script>
<h3 class="mt-4">۲۰ فایل حجیم</h3>
<table class="text-sm"><thead><tr><th>مسیر</th><th>حجم (MB)</th></tr></thead><tbody>
<?php foreach($upload_stats['top'] as $f){echo '<tr><td>'.str_replace(__DIR__,'',$f['path']).'</td><td>'.round($f['size']/1024/1024,2).'</td></tr>'; } ?>
</tbody></table>
</div>

<div x-show="tab===4" class="bg-white p-4 shadow">
<h2 class="text-xl mb-4">لاگ‌ها و بک‌آپ‌ها</h2>
<?php
$files = glob($backup_dir.'/*.sql');
if($files){echo '<ul>';foreach($files as $f){$b=basename($f);echo "<li><a class='text-blue-600 underline' href='wp-content/uploads/wp-maintenance-backups/$b'>$b</a> (".round(filesize($f)/1024/1024,2).' MB)</li>'; } echo '</ul>';} else echo 'بک‌آپی وجود ندارد.';
?>
</div>

</body></html>