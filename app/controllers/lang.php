<?php
declare(strict_types=1);

/** Switch UI language and return to the previous page. */
$lang = (string) input('lang', 'zh');
$_SESSION['lang'] = in_array($lang, ['zh', 'id'], true) ? $lang : 'zh';

$back = $_SERVER['HTTP_REFERER'] ?? '';
if ($back !== '' && str_contains($back, $_SERVER['HTTP_HOST'] ?? 'localhost')) {
    header('Location: ' . $back);
} else {
    header('Location: ' . url('dashboard.index'));
}
exit;
